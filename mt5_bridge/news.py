"""Optional HTTPS source for the documented calendar JSON format."""
import json
import os
from urllib.request import Request, HTTPRedirectHandler, build_opener
from urllib.parse import urlparse

from risk import Rejected

_cached = None
_fetched_at = None
_cached_url = None


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise Rejected('News feed redirects are not accepted')


def fetch_calendar(now):
    global _cached, _fetched_at, _cached_url
    url = os.getenv("MT5_NEWS_URL", "")
    if not url:
        return None  # Fall back to the maintained local file.
    parsed = urlparse(url)
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
        raise Rejected("News URL must use HTTPS without embedded credentials")
    if _cached_url == url and _fetched_at is not None and 0 <= (now - _fetched_at).total_seconds() < 300:
        return _cached
    headers = {"Accept": "application/json", "User-Agent": "GoldLogic/1.0"}
    if os.getenv("MT5_NEWS_TOKEN"):
        headers["Authorization"] = "Bearer " + os.environ["MT5_NEWS_TOKEN"]
    try:
        with build_opener(NoRedirect()).open(Request(url, headers=headers), timeout=5) as response:
            if response.geturl() != url:
                raise Rejected("News feed redirects are not accepted")
            content = response.read(1_000_001)
            if len(content) > 1_000_000:
                raise Rejected("News response exceeds the size limit")
            data = json.loads(content)
            if not isinstance(data, dict):
                raise Rejected("News feed must return a JSON object")
    except Exception as exc:
        # Do not expose URLs, credentials or third-party response bodies.
        raise Rejected("News feed refresh failed; new entries are paused") from exc
    _cached, _fetched_at, _cached_url = data, now, url
    return data
