#!/usr/bin/env python3
"""Exercise the real system curl against an isolated HTTPS fault server; never install a runtime."""
import hashlib
import http.server
import json
import os
from pathlib import Path
import signal
import ssl
import subprocess
import tempfile
import threading
import time

ROOT = Path(__file__).resolve().parents[1]
DATA = bytes(range(256)) * 4096
SHA = hashlib.sha256(DATA).hexdigest()
requests = []
mode = 'normal'
drops = 0

class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        global drops
        value = self.headers.get('Range')
        offset = int(value.split('=')[1].split('-')[0]) if value else 0
        status = 206 if value else 200
        if mode == 'no-range':
            offset, status = 0, 200
        if mode == '416' and value:
            status = 416
        if mode == 'error':
            status = 503
        body = DATA[offset:] if status in (200, 206) else b'error response'
        self.send_response(status)
        self.send_header('Content-Length', str(len(body)))
        if status == 206:
            self.send_header('Content-Range', f'bytes {offset}-{len(DATA)-1}/{len(DATA)}')
        self.end_headers()
        count = len(body)
        if drops:
            drops -= 1
            count = min(65536, len(body) // 2)
        record = {'range': value, 'status': status, 'bytes': 0}
        requests.append(record)
        try:
            if mode == 'slow':
                for i in range(0, len(body), 4096):
                    chunk = body[i:i+4096]
                    self.wfile.write(chunk); self.wfile.flush(); record['bytes'] += len(chunk); time.sleep(.03)
            else:
                self.wfile.write(body[:count]); self.wfile.flush(); record['bytes'] = count
        except (BrokenPipeError, ConnectionResetError, ssl.SSLEOFError):
            pass
        self.close_connection = True

with tempfile.TemporaryDirectory(prefix='aot-download-', dir=os.environ.get('WEBMAN_AOT_TEST_TMP')) as tmp:
    base = Path(tmp)
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                    '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost',
                    '-keyout', str(base/'key.pem'), '-out', str(base/'cert.pem')],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(base/'cert.pem', base/'key.pem')
    server.socket = context.wrap_socket(server.socket, server_side=True)
    worker = threading.Thread(target=server.serve_forever, daemon=True); worker.start()
    env = dict(os.environ, CURL_CA_BUNDLE=str(base/'cert.pem'))
    package = {'filename': 'test-full.bin', 'size': len(DATA), 'sha256': SHA,
               'url': f'https://localhost:{server.server_port}/full'}
    entry = base/'entry.php'
    entry.write_text('<?php require ' + repr(str(ROOT/'src/Installer.php')) + '; require '
                     + repr(str(ROOT/'src/Archive.php')) + '; require '
                     + repr(str(ROOT/'src/Process.php')) + '; $p=json_decode($argv[2],true); '
                     + 'try { (new ReflectionMethod(Supdger\\WebmanAotInstaller\\Installer::class,"download"))->invoke('
                     + 'new Supdger\\WebmanAotInstaller\\Installer(null,false),$argv[1],$p,"macos-arm64"); } '
                     + 'catch(Throwable $e) { fwrite(STDERR,$e->getMessage()."\\n"); exit(70); }')
    def state(name, prefix=None):
        path = base/name; (path/'cache').mkdir(parents=True)
        if prefix is not None: partial(path).write_bytes(prefix)
        return path
    def partial(path):
        return path/'cache'/f'test-full.bin.{SHA}.part'
    def invoke(path, expected=0):
        code = subprocess.run(['php', str(entry), str(path), json.dumps(package)], env=env).returncode
        assert code == expected, (code, expected)
    def verified(path):
        assert (path/'cache/test-full.bin').read_bytes() == DATA
        assert not partial(path).exists()
    try:
        drops = 1; p = state('retry'); start = len(requests); invoke(p); verified(p)
        assert requests[start:start+2] == [{'range': None, 'status': 200, 'bytes': 65536},
                                         {'range': 'bytes=65536-', 'status': 206, 'bytes': len(DATA)-65536}]
        print('[通过] 中断后自动重试从 65536 字节续传', flush=True)
        start = len(requests); invoke(p); assert len(requests) == start
        print('[通过] 完整缓存复用不发起网络请求', flush=True)
        drops = 3; p = state('restart'); invoke(p, 70)
        offset = partial(p).stat().st_size; assert offset == 3 * 65536
        start = len(requests); invoke(p); verified(p)
        assert requests[start]['range'] == f'bytes={offset}-'
        print(f'[通过] 三次失败保留 {offset} 字节，新进程继续下载', flush=True)
        for scenario in ('no-range', '416'):
            mode = scenario; p = state(scenario, DATA[:12345]); start = len(requests)
            invoke(p); verified(p)
            assert [r['range'] for r in requests[start:]] == ['bytes=12345-', None]
            print(f'[通过] {scenario} 拒绝续传后安全完整重下载', flush=True)
        mode = 'normal'; p = state('bad-prefix', b'x'*12345); invoke(p,70)
        assert not partial(p).exists() and not (p/'cache/test-full.bin').exists()
        print('[通过] 被污染的部分文件最终 SHA-256 拒绝且清除，不生成完整缓存', flush=True)
        mode = 'error'; p = state('http-error', DATA[:12345]); invoke(p,70)
        assert partial(p).read_bytes() == DATA[:12345]
        print('[通过] HTTP 503 错误正文不污染续传文件', flush=True)
        mode = 'slow'; p = state('cancel'); start = len(requests)
        process = subprocess.Popen(['php', str(entry), str(p), json.dumps(package)], env=env, start_new_session=True)
        deadline = time.monotonic()+10
        while (not partial(p).exists() or partial(p).stat().st_size < 16384) and time.monotonic()<deadline:
            time.sleep(.02)
        os.killpg(process.pid, signal.SIGINT); process.wait(timeout=5)
        offset = partial(p).stat().st_size; assert 0 < offset < len(DATA)
        mode = 'normal'; start = len(requests); invoke(p); verified(p)
        assert requests[start]['range'] == f'bytes={offset}-'
        print(f'[通过] Ctrl+C 保留 {offset} 字节，重启从真实位置续传', flush=True)
        p = state('already-complete', DATA); start = len(requests); invoke(p); verified(p)
        assert len(requests) == start
        print('[通过] 完整 part 先校验后转正，无网络请求', flush=True)
        print('[证据] 实际 HTTPS 请求：'+json.dumps(requests), flush=True)
    finally:
        server.shutdown(); server.server_close(); worker.join()
        print('[收尾] 隔离 HTTPS 服务已停止；临时证书、包、状态目录随测试清理。', flush=True)
