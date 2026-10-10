#!/usr/bin/env python3
"""Darwin parent-death regression with actual Composer, console pipes and owned leaf workers."""
import errno
import hashlib
import http.server
import ssl
import threading
import json
import os
from pathlib import Path
import pty
import select
import shutil
import signal
import subprocess
import sys
import tempfile
import time

if sys.platform != 'darwin':
    raise SystemExit('此控制终端回归仅验收 macOS；Windows 另需原生验收。')
source = Path(__file__).resolve().parents[1]
base = Path(tempfile.mkdtemp(prefix='aot-parent-death-'))
passed = 0

def check(value, message):
    global passed
    if not value:
        raise AssertionError(message)
    passed += 1
    print('[通过] ' + message, flush=True)

def run_case(name, action=None, worker_exit=0, composer=True):
    root = base / name
    (root / 'src').mkdir(parents=True)
    (root / 'bin').mkdir()
    for filename in ['Console.php', 'Process.php']:
        shutil.copyfile(source / 'src' / filename, root / 'src' / filename)
    (root / 'composer.json').write_text('{"name":"fixture/parent-death","require":{}}\n')
    (root / 'download.part').write_text('already downloaded bytes\n')
    (root / 'worker.php').write_text('''<?php
file_put_contents(__DIR__.'/worker.pid', getmypid());
for ($i=0; $i<(int)$argv[1]; $i++) {
    file_put_contents(__DIR__.'/download.part', "received $i\\n", FILE_APPEND);
    echo "[native progress] $i\\n"; flush(); sleep(1);
}
echo "[native complete]\\n";
exit((int)$argv[2]);
''')
    (root / 'bin' / 'webman-aot').write_text('''<?php
require dirname(__DIR__).'/src/Process.php';
require dirname(__DIR__).'/src/Console.php';
use Supdger\\WebmanAotInstaller\\Console;
use Supdger\\WebmanAotInstaller\\Process;
$root=dirname(__DIR__);
try {
    if (getenv('WEBMAN_AOT_GUIDE_CONSOLE_DEPTH') !== '1') {
        file_put_contents($root.'/outer.pid',getmypid());
        $code=Console::restore($argv); if ($code!==null) { exit($code); }
    }
    file_put_contents($root.'/guide.pid',getmypid());
    echo "[menu] select 1 or 0\\n";
    if (trim((string)Console::read()) !== '1') { exit(0); }
    $code=Process::download([PHP_BINARY,$root.'/worker.php',$argv[2],$argv[3]]);
    echo "[leaf exit] $code\\n";
    exit($code);
} catch (Throwable $error) { echo '[stopped] '.$error->getMessage()."\\n"; exit(70); }
''')
    duration = '2' if action is None else '8'
    args = ['composer', 'exec', '--', 'php', str(root / 'bin' / 'webman-aot'), 'guide', duration, str(worker_exit)] if composer else ['php', str(root / 'bin' / 'webman-aot'), 'guide', duration, str(worker_exit)]
    pid, master = pty.fork()
    if pid == 0:
        os.chdir(root)
        env = os.environ.copy()
        env.update(COMPOSER_HOME=str(root / 'composer-home'), COMPOSER_CACHE_DIR=str(root / 'composer-cache'),
                   COMPOSER_DISABLE_NETWORK='1', COMPOSER_PROCESS_TIMEOUT='1' if action == 'timeout' else '30', CI='', COMPOSER_NO_INTERACTION='')
        os.execvpe(args[0], args, env)
    output = b''; selected = killed = False; started = time.monotonic()
    print('[步骤] ' + name + ': ' + ' '.join(args), flush=True)
    try:
        while True:
            if time.monotonic()-started > 10:
                raise TimeoutError(name + ' exceeded bounded fixture time')
            if select.select([master], [], [], .1)[0]:
                try: chunk = os.read(master, 65536)
                except OSError as error:
                    if error.errno == errno.EIO: break
                    raise
                if not chunk: break
                output += chunk
                print(chunk.decode(errors='replace'), end='', flush=True)
            if b'[menu]' in output and not selected and action != 'menu-parent-kill':
                os.write(master, b'1\n'); selected = True
            ready = b'[menu]' in output if action == 'menu-parent-kill' else b'[native progress]' in output
            if ready and not killed and action in ['parent-kill', 'menu-parent-kill', 'ctrl-c']:
                if action == 'ctrl-c': os.killpg(pid, signal.SIGINT)
                else:
                    outer = int((root / 'outer.pid').read_text())
                    command = subprocess.check_output(['/bin/ps','-p',str(outer),'-o','command='], text=True)
                    assert str(root / 'bin' / 'webman-aot') in command
                    os.kill(outer, signal.SIGKILL)
                killed = True
        _, status = os.waitpid(pid, 0)
        code = os.waitstatus_to_exitcode(status)
        print(f'[结束] {name}: exit={code}, elapsed={time.monotonic()-started:.2f}s', flush=True)
        for filename in ['outer.pid', 'guide.pid', 'worker.pid']:
            if (root / filename).exists():
                process_id = (root / filename).read_text()
                result = subprocess.run(['/bin/ps','-p',process_id,'-o','stat=,command='], capture_output=True, text=True)
                check(str(root) not in result.stdout or result.stdout.strip().startswith('Z'), name + ': no running ' + filename)
        text = output.decode(errors='replace')
        check((root / 'download.part').read_text().startswith('already downloaded bytes\n'), name + ': previous partial bytes retained')
        check(not (root / 'ready.json').exists(), name + ': no ready/install state created')
        if action is None:
            check(code == worker_exit and f'[leaf exit] {worker_exit}' in text and '[native complete]' in text, name + ': native output/exit status preserved')
        else:
            check(code != 0 and '[native complete]' not in text and '[leaf exit]' not in text, name + ': cancellation prevents later completion')
        return root, text
    finally:
        os.close(master)
        # This session/process group belongs only to this temporary fixture.
        try: os.killpg(pid, signal.SIGKILL)
        except ProcessLookupError: pass

def real_entry_timeout(menu_only=False, default_entry=False):
    root = base / (('default-' if default_entry else '') + ('real-menu' if menu_only else 'real-entry'))
    root.mkdir()
    package = root / 'package'
    shutil.copytree(source, package)
    print('[源码快照] ' + json.dumps({name: hashlib.sha256((package/'src'/name).read_bytes()).hexdigest() for name in ['Console.php','Process.php','Installer.php']}), flush=True)
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost',
                    '-addext','subjectAltName=DNS:localhost','-keyout',str(root/'key.pem'),'-out',str(root/'cert.pem')],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    data = bytes(range(256)) * 4096
    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self, *args): pass
        def do_GET(self):
            self.send_response(200); self.send_header('Content-Length',str(len(data))); self.end_headers()
            try:
                for offset in range(0,len(data),4096):
                    self.wfile.write(data[offset:offset+4096]);self.wfile.flush();time.sleep(.03)
            except (BrokenPipeError,ConnectionResetError,ssl.SSLEOFError): pass
    server = http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
    server.daemon_threads = True
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(root/'cert.pem',root/'key.pem')
    server.socket=context.wrap_socket(server.socket,server_side=True)
    threading.Thread(target=server.serve_forever,daemon=True).start()
    release_path=package/'resources/releases.json'
    release=json.loads(release_path.read_text());artifact=release['packages']['macos-arm64']
    artifact.update(filename='fixture-full.zip',size=len(data),sha256=hashlib.sha256(data).hexdigest(),url=f'https://localhost:{server.server_port}/full')
    release_path.write_text(json.dumps(release))
    (root/'composer.json').write_text('{"name":"fixture/real-entry","require":{}}\n')
    pid,master=pty.fork()
    if pid==0:
        os.chdir(root);env=os.environ.copy();env.update(CURL_CA_BUNDLE=str(root/'cert.pem'),COMPOSER_HOME=str(root/'composer-home'),
           COMPOSER_CACHE_DIR=str(root/'composer-cache'),COMPOSER_DISABLE_NETWORK='1',COMPOSER_PROCESS_TIMEOUT='30' if menu_only else '1',CI='',COMPOSER_NO_INTERACTION='')
        os.execvpe('/bin/zsh',['zsh','-f'],env)
    entry = '' if default_entry else 'guide'
    command=f"PROMPT='[SHELL PROMPT] '; composer exec -- php '{package}/bin/webman-aot' {entry} --state-dir='{root}/state'; printf '[RETURN] exit=%s\\n' $?\n"
    os.write(master,command.encode());output=b'';selected=recovered=False;started=time.monotonic()
    print('[步骤] real production ' + ('default entry' if default_entry else 'explicit guide') + ' + interactive shell: ' + ('normal menu exit' if menu_only else 'scaled Composer timeout'),flush=True)
    expected = b'[RETURN] exit=0\r\n' if menu_only else b'[RETURN] exit=1\r\n'
    try:
        while time.monotonic()-started<8:
            if select.select([master],[],[],.1)[0]:
                try:chunk=os.read(master,65536)
                except OSError as error:
                    if error.errno==errno.EIO:break
                    raise
                if not chunk:break
                output+=chunk;print(chunk.decode(errors='replace'),end='',flush=True)
            if '0 结束'.encode() in output and not selected:
                os.write(master,b'0\n' if menu_only else b'1\n');selected=True
            if expected in output and not recovered:
                os.write(master,b"printf '[INPUT RESTORED] yes\\n'\n");recovered=True
            if b'[INPUT RESTORED] yes\r\n' in output and time.monotonic()-started>3:break
        text=output.decode(errors='replace')
        check('[INPUT RESTORED] yes\r\n' in text,'real entry: shell command executes after timeout without exiting terminal')
        check('[SHELL PROMPT]' in text.split(expected.decode())[-1],'real entry: shell prompt restored after exit')
        if menu_only:
            check(expected.decode() in text and '[失败]' not in text,'real entry: normal guided exit remains zero with parent pipe held')
            check(not (root/'state').exists(),'real entry: normal menu exit does not prepare resources')
            return
        partials=list((root/'state/cache').glob('*.part'))
        check(len(partials)==1 and 0<partials[0].stat().st_size<len(data),'real entry: partial download retained')
        size=partials[0].stat().st_size;time.sleep(.2)
        check(partials[0].stat().st_size==size,'real entry: curl stops writing after parent timeout')
        check(not (root/'state/ready.json').exists() and not (root/'state/runtime').exists(),'real entry: cancellation neither installs nor marks ready')
        process_list=subprocess.check_output(['/bin/ps','-axo','pid=,stat=,command='],text=True)
        check(str(package/'bin/webman-aot') not in process_list and f'--output {partials[0]}' not in process_list,'real entry: no restored guide or curl residual')
        print(f'[结束] real-entry: elapsed={time.monotonic()-started:.2f}s',flush=True)
    finally:
        os.killpg(pid,signal.SIGHUP);os.waitpid(pid,0);os.close(master)
        shutdown=threading.Thread(target=server.shutdown,daemon=True);shutdown.start();shutdown.join(2);server.server_close()

try:
    if '--real-entry-only' not in sys.argv and '--default-entry-only' not in sys.argv:
        run_case('success')
        run_case('native-failure', worker_exit=7)
        run_case('composer-timeout', action='timeout')
        run_case('parent-killed', action='parent-kill')
        run_case('menu-parent-killed', action='menu-parent-kill')
        run_case('ctrl-c', action='ctrl-c')
        run_case('direct-php', composer=False)
    if '--default-entry-only' not in sys.argv:
        real_entry_timeout(menu_only=True)
        real_entry_timeout()
    real_entry_timeout(menu_only=True, default_entry=True)
    real_entry_timeout(default_entry=True)
    print(f'完成：{passed} 项父入口生命周期检查通过；仅 owned native leaf。', flush=True)
finally:
    shutil.rmtree(base)
    print('本次隔离夹具已清理：' + str(not base.exists()), flush=True)
