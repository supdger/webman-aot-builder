#!/usr/bin/env python3
"""Keep one native-test command in its own session until its children exit."""
import argparse
import hashlib
import json
import os
import pathlib
import signal
import stat
import subprocess
import sys
import tarfile
import time


def resource_mapping(value):
    # PHP encodes its empty associative array as [], while populated maps are objects.
    if value == []:
        return {}
    if not isinstance(value, dict):
        raise RuntimeError("runtime resources must be an object or an empty array")
    return value


def directory_aliases(root_text):
    """Observe directory links without traversing them or changing source paths."""
    root = pathlib.Path(root_text)
    if not root.is_absolute() or root.is_symlink() or root.resolve(strict=True) != root:
        raise RuntimeError("directory alias input root must be a physical absolute directory")
    if not root.is_dir():
        raise RuntimeError("directory alias input root is not a directory")
    aliases = []
    def walk_error(error):
        raise error

    observed_entries = 1
    for parent, directories, files in os.walk(root, followlinks=False, onerror=walk_error):
        observed_entries += len(directories) + len(files)
        if observed_entries > 32768:
            raise RuntimeError("directory alias observation exceeds the input entry bound")
        directories.sort()
        for name in list(directories):
            path = pathlib.Path(parent) / name
            link_stat = path.lstat()
            if not stat.S_ISLNK(link_stat.st_mode):
                continue
            directories.remove(name)
            target = os.readlink(path)
            raw_target = pathlib.Path(target)
            if raw_target.is_absolute():
                if raw_target.parts[:len(root.parts)] != root.parts:
                    raise RuntimeError("directory alias target must stay inside the input root")
                canonical_target, parts = root, raw_target.parts[len(root.parts):]
            else:
                canonical_target, parts = path.parent, raw_target.parts
            for part in parts:
                canonical_target = canonical_target.parent if part == ".." else canonical_target / part
                if not canonical_target.is_relative_to(root):
                    raise RuntimeError("directory alias target must stay inside the input root")
                if not stat.S_ISDIR(canonical_target.lstat().st_mode):
                    raise RuntimeError("directory alias target components must be physical directories")
            if path.resolve(strict=True) != canonical_target:
                raise RuntimeError("directory alias target changed while observed")
            if canonical_target in path.parents:
                raise RuntimeError("directory alias target must not be an ancestor directory")
            target_stat = canonical_target.lstat()
            if not stat.S_ISDIR(target_stat.st_mode):
                raise RuntimeError("directory alias target is not a physical directory")
            aliases.append({
                "path": str(path), "target": target,
                "id": [link_stat.st_dev, link_stat.st_ino],
                "mtime": link_stat.st_mtime_ns, "ctime": link_stat.st_ctime_ns,
                "canonicalTarget": str(canonical_target),
                "targetId": [target_stat.st_dev, target_stat.st_ino],
                "targetMtime": target_stat.st_mtime_ns, "targetCtime": target_stat.st_ctime_ns,
            })
            if len(aliases) > 64:
                raise RuntimeError("directory alias observation exceeds the 64-alias bound")
    parents = [pathlib.Path(alias["path"]).parent for alias in aliases]
    edges = [
        [index for index, parent in enumerate(parents)
         if pathlib.Path(alias["canonicalTarget"]) == parent
         or pathlib.Path(alias["canonicalTarget"]) in parent.parents]
        for alias in aliases
    ]
    active, complete = set(), set()

    def visit(index):
        if index in active:
            raise RuntimeError("directory aliases must not form a traversal cycle")
        if index in complete:
            return
        active.add(index)
        for child in edges[index]:
            visit(child)
        active.remove(index)
        complete.add(index)

    for index in range(len(aliases)):
        visit(index)
    return aliases


def runtime_files(payload):
    files = {
        "artifact/server": (payload["elf"], payload["elf_sha256"], 0o755),
        "runtime.ini": (payload["ini"], payload["ini_sha256"], 0o644),
    }
    for relative, source in resource_mapping(payload["resources"]).items():
        target = pathlib.PurePosixPath(relative)
        if target.is_absolute() or ".." in target.parts or str(target) in ("", ".", "server"):
            raise RuntimeError("unsafe runtime resource destination")
        files["artifact/" + str(target)] = (source, payload["resource_sha256"][relative], 0o644)
    return files


def write_runtime_archive(stream, files):
    with tarfile.open(fileobj=stream, mode="w|") as archive:
        directories = {"artifact"}
        for target in files:
            directories.update(str(parent) for parent in pathlib.PurePosixPath(target).parents if str(parent) != ".")
        for directory in sorted(directories, key=lambda value: (value.count("/"), value)):
            member = tarfile.TarInfo(directory)
            member.type = tarfile.DIRTYPE
            member.mode = 0o755
            archive.addfile(member)
        for target, (source, _, mode) in files.items():
            info = pathlib.Path(source).lstat()
            if not stat.S_ISREG(info.st_mode):
                raise RuntimeError("runtime input must be a regular file")
            member = tarfile.TarInfo(target)
            member.size = info.st_size
            member.mode = mode
            with open(source, "rb") as content:
                archive.addfile(member, content)


def import_runtime(payload_path):
    """Import an exact runtime-only archive; read-only containers cannot accept writes."""
    payload = json.loads(pathlib.Path(payload_path).read_text())
    files = runtime_files(payload)
    command = [payload["docker"], "import", "--platform", "linux/amd64", "--change",
               "LABEL webman-aot.native-regression=" + payload["snapshot"], "-", payload["runtime_image_name"]]
    print("runtime image tar-stream import: " + str(len(files)) + " declared files", file=sys.stderr, flush=True)
    process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE)
    stream_error = None
    try:
        write_runtime_archive(process.stdin, files)
    except BaseException as error:
        stream_error = error
    try:
        process.stdin.close()
    except BrokenPipeError as error:
        stream_error = stream_error or error
    output = process.stdout.read()
    process.stdout.close()
    code = process.wait()
    if code:
        print("runtime image import failed: " + str(code), file=sys.stderr, flush=True)
        sys.exit(code if code >= 0 else 128 - code)
    if stream_error:
        raise stream_error
    image_id = output.decode().strip()
    if len(image_id) != 71 or not image_id.startswith("sha256:") or any(c not in "0123456789abcdef" for c in image_id[7:]):
        raise RuntimeError("runtime image import returned an invalid identity")
    print(json.dumps({"transport": "docker-import-tar-stream", "image_id": image_id,
                      "image_name": payload["runtime_image_name"], "declared_files": list(files)}))


def verify_runtime(payload_path):
    payload = json.loads(pathlib.Path(payload_path).read_text())
    files = runtime_files(payload)
    verified = {}
    for target, (_, expected, mode) in files.items():
        check = subprocess.Popen(
            [payload["docker"], "cp", payload["container_id"] + ":/" + target, "-"],
            stdout=subprocess.PIPE,
        )
        members_seen = 0
        with tarfile.open(fileobj=check.stdout, mode="r|") as archive:
            for member in archive:
                if not member.isfile() or member.name != pathlib.PurePosixPath(target).name:
                    raise RuntimeError("unexpected copied runtime member")
                digest = hashlib.sha256()
                content = archive.extractfile(member)
                for chunk in iter(lambda: content.read(65536), b""):
                    digest.update(chunk)
                if digest.hexdigest() != expected or member.mode & 0o777 != mode:
                    raise RuntimeError("deployed runtime bytes or mode differ: " + target)
                verified[target] = {"sha256": expected, "size": member.size, "mode": mode}
                members_seen += 1
        check.stdout.close()
        copy_exit = check.wait()
        if copy_exit:
            print("runtime archive readback failed: " + str(copy_exit), file=sys.stderr, flush=True)
            sys.exit(copy_exit if copy_exit >= 0 else 128 - copy_exit)
        if members_seen != 1:
            raise RuntimeError("runtime archive verification failed")
        print("runtime copied bytes verified: " + target, file=sys.stderr, flush=True)
    print(json.dumps({"transport": "docker-import-tar-stream", "files": verified}))


if len(sys.argv) == 3 and sys.argv[1] == "--directory-aliases":
    print(json.dumps(directory_aliases(sys.argv[2])))
    sys.exit(0)

if sys.argv[1:] == ["--check-transport"]:
    for encoded in ("[]", "{}"):
        if resource_mapping(json.loads(encoded)) != {}:
            raise RuntimeError("empty resource protocol differs")
    if resource_mapping({"nested/data.txt": "/persistent/data.txt"}) != {"nested/data.txt": "/persistent/data.txt"}:
        raise RuntimeError("resource mapping was altered")
    for invalid in ([1], None, "resource"):
        try:
            resource_mapping(invalid)
        except RuntimeError:
            continue
        raise RuntimeError("invalid resource protocol was accepted")
    print("PASS transport resource JSON protocol; not a native suite result")
    sys.exit(0)

if len(sys.argv) == 3 and sys.argv[1] in ("--import-runtime", "--verify-runtime"):
    (import_runtime if sys.argv[1] == "--import-runtime" else verify_runtime)(sys.argv[2])
    sys.exit(0)


def members(group):
    query = subprocess.Popen(
        ["ps", "-axo", "pid=,pgid=,stat="], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True
    )
    output, error = query.communicate(timeout=5)
    if query.returncode:
        raise RuntimeError("cannot inspect command group: " + error)
    return [
        int(parts[0])
        for line in output.splitlines()
        if len(parts := line.split()) == 3
        and int(parts[1]) == group
        and int(parts[0]) != query.pid
        and not parts[2].startswith("Z")
    ]


def read_record(path, nonce):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
        raise RuntimeError("untrusted command process receipt")
    record = json.loads(path.read_text())
    if record["nonce"] != nonce or record["uid"] != os.getuid():
        raise RuntimeError("command process receipt identity differs")
    return record


parser = argparse.ArgumentParser()
parser.add_argument("--terminate", action="store_true")
parser.add_argument("receipt")
parser.add_argument("nonce")
parser.add_argument("command", nargs=argparse.REMAINDER)
args = parser.parse_args()
path = pathlib.Path(args.receipt)
if args.terminate:
    record = read_record(path, args.nonce)
    leader = record["pid"]
    # The PHP caller also requires its proc_open handle to still be running.
    # This supervisor stays alive while group children remain, preventing reuse.
    if leader <= 1 or leader == os.getpgrp() or record["pgid"] != leader or record["sid"] != leader:
        raise RuntimeError("refusing a shared or malformed process group")
    try:
        if os.getpgid(leader) != leader or os.getsid(leader) != leader:
            raise RuntimeError("command session leader changed")
        uid = int(subprocess.check_output(["ps", "-o", "uid=", "-p", str(leader)], text=True).strip())
        if uid != record["uid"]:
            raise RuntimeError("command session owner changed")
        os.killpg(leader, signal.SIGKILL)
    except ProcessLookupError:
        pass
    deadline = time.monotonic() + 2
    remaining = members(leader)
    while remaining and time.monotonic() < deadline:
        time.sleep(0.05)
        remaining = members(leader)
    print(json.dumps({"pgid": leader, "remaining_pids": remaining}))
    sys.exit(1 if remaining else 0)

command = args.command[1:] if args.command[:1] == ["--"] else args.command
if not command:
    parser.error("provide a command")
os.setsid()
record = {
    "pid": os.getpid(), "pgid": os.getpgrp(), "sid": os.getsid(0),
    "uid": os.getuid(), "nonce": args.nonce, "command": command,
    "command_exit": None,
}
with path.open("x") as output:
    os.chmod(path, 0o600)
    json.dump(record, output)
process = subprocess.Popen(command)
code = process.wait()
record["command_exit"] = code
temporary = path.with_name(path.name + ".completed")
with temporary.open("x") as output:
    os.chmod(temporary, 0o600)
    json.dump(record, output)
os.replace(temporary, path)
# Keep the trusted session leader alive if the command exited before children.
while any(pid != os.getpid() for pid in members(os.getpid())):
    time.sleep(0.05)
sys.exit(code if code >= 0 else 128 - code)
