#!/usr/bin/env python3
"""Small development server for the compiled frontend with an /api proxy."""

from __future__ import annotations

import argparse
import json
import os
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen


class FrontendHandler(SimpleHTTPRequestHandler):
    api_url = "http://127.0.0.1:8000"

    def do_GET(self) -> None:  # noqa: N802 - stdlib handler API
        if self._is_api_request():
            self._proxy_api()
            return
        requested = urlsplit(self.path).path
        local_path = Path(self.translate_path(requested))
        if requested != "/" and not local_path.is_file():
            self.path = "/index.html"
        super().do_GET()

    def do_HEAD(self) -> None:  # noqa: N802 - stdlib handler API
        if self._is_api_request():
            self._proxy_api()
            return
        super().do_HEAD()

    def do_POST(self) -> None:  # noqa: N802 - stdlib handler API
        self._proxy_api()

    def do_PUT(self) -> None:  # noqa: N802 - stdlib handler API
        self._proxy_api()

    def do_DELETE(self) -> None:  # noqa: N802 - stdlib handler API
        self._proxy_api()

    def _is_api_request(self) -> bool:
        path = urlsplit(self.path).path
        return path == "/api" or path.startswith("/api/")

    def _proxy_api(self) -> None:
        if not self._is_api_request():
            self.send_error(404, "Not found")
            return
        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length) if length else None
        upstream = Request(
            self.api_url.rstrip("/") + self.path,
            data=body,
            method=self.command,
            headers={
                "Accept": self.headers.get("Accept", "application/json"),
                "Content-Type": self.headers.get("Content-Type", "application/json"),
                "X-Requested-With": self.headers.get("X-Requested-With", "XMLHttpRequest"),
            },
        )
        try:
            with urlopen(upstream, timeout=65) as response:
                payload = response.read()
                self.send_response(response.status)
                self.send_header("Content-Type", response.headers.get("Content-Type", "application/json"))
                self.send_header("Content-Length", str(len(payload)))
                self.send_header("Cache-Control", "no-store")
                self.end_headers()
                self.wfile.write(payload)
        except HTTPError as error:
            payload = error.read()
            self.send_response(error.code)
            self.send_header("Content-Type", error.headers.get("Content-Type", "application/json"))
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)
        except URLError as error:
            payload = json.dumps({"error": f"Backend is unavailable: {error.reason}"}).encode()
            self.send_response(502)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=5173)
    parser.add_argument("--directory", default="frontend/dist")
    parser.add_argument("--api-url", default="http://127.0.0.1:8000")
    args = parser.parse_args()

    directory = Path(args.directory).resolve()
    if not (directory / "index.html").is_file():
        raise SystemExit(f"Frontend build not found at {directory}; run npm run build first.")
    os.chdir(directory)
    FrontendHandler.api_url = args.api_url
    server = ThreadingHTTPServer((args.host, args.port), FrontendHandler)
    print(f"Frontend: http://{args.host}:{args.port} -> API {args.api_url}", flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
