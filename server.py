import http.server
import json
import os

CONFIG_FILE = 'config.json'
PORT = 8000

class Handler(http.server.SimpleHTTPRequestHandler):
    def do_POST(self):
        if self.path == '/api/save':
            content_length = int(self.headers.get('Content-Length', 0))
            body = self.rfile.read(content_length)
            try:
                data = json.loads(body.decode('utf-8'))
                
                # 先写入临时文件，再重命名，确保原子写入
                temp_file = CONFIG_FILE + '.tmp'
                with open(temp_file, 'w', encoding='utf-8') as f:
                    json.dump(data, f, ensure_ascii=False, indent=2)
                    f.flush()
                    os.fsync(f.fileno())
                
                # 验证写入内容
                with open(temp_file, 'r', encoding='utf-8') as f:
                    saved = json.load(f)
                
                if saved.get('cards') is not None:
                    os.replace(temp_file, CONFIG_FILE)
                    self._send_json(200, {'success': True, 'cards': len(saved.get('cards', []))})
                else:
                    os.remove(temp_file)
                    self._send_json(500, {'error': '数据验证失败'})
            except Exception as e:
                self._send_json(500, {'error': str(e)})
        else:
            self.send_response(404)
            self.end_headers()

    def do_OPTIONS(self):
        self.send_response(200)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')
        self.end_headers()

    def _send_json(self, code, data):
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()
        self.wfile.write(json.dumps(data, ensure_ascii=False).encode('utf-8'))

if __name__ == '__main__':
    if not os.path.exists(CONFIG_FILE):
        with open(CONFIG_FILE, 'w', encoding='utf-8') as f:
            json.dump({'version': 1, 'cards': [], 'history': {}}, f, ensure_ascii=False, indent=2)
    
    server = http.server.HTTPServer(('localhost', PORT), Handler)
    print(f'服务器已启动: http://localhost:{PORT}')
    print(f'数据文件: {os.path.abspath(CONFIG_FILE)}')
    print('按 Ctrl+C 停止服务器')
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print('\n服务器已停止')
