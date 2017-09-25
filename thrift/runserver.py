#!/usr/bin/env python

import BaseHTTPServer
import CGIHTTPServer
import sys

class Handler(CGIHTTPServer.CGIHTTPRequestHandler):
    cgi_directories  = ['/']

ip = sys.argv[1] if 1 in range(len(sys.argv)) else '192.168.0.222'
port = sys.argv[2] if 2 in range(len(sys.argv)) else '8888'

BaseHTTPServer.HTTPServer((ip, int(port)), Handler).serve_forever()
