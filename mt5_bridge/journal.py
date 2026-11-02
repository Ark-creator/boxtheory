"""Durable at-most-once submissions. Unknown broker outcomes must be reconciled."""
import json
import sqlite3
from contextlib import contextmanager


class Journal:
    def __init__(self, path):
        self.path = str(path)
        with self.connect() as db:
            db.execute("CREATE TABLE IF NOT EXISTS requests (id TEXT PRIMARY KEY, signal_key TEXT UNIQUE NOT NULL, response TEXT NOT NULL)")
            db.execute("CREATE TABLE IF NOT EXISTS state (key TEXT PRIMARY KEY, value TEXT NOT NULL)")

    @contextmanager
    def connect(self):
        db = sqlite3.connect(self.path, timeout=10)
        try:
            db.execute("PRAGMA synchronous=FULL")
            # sqlite3's own context manager commits/rolls back, but does not
            # close the connection. Close explicitly after either outcome.
            with db:
                yield db
        finally:
            db.close()

    def existing(self, request_id, signal_key):
        with self.connect() as db:
            row = db.execute("SELECT response FROM requests WHERE id=? OR signal_key=?", (request_id, signal_key)).fetchone()
        return json.loads(row[0]) if row else None

    def reserve(self, request_id, signal_key):
        with self.connect() as db:
            db.execute("INSERT INTO requests VALUES (?, ?, ?)", (request_id, signal_key, json.dumps({"status": "unknown", "message": "Submission reserved. Reconcile with MT5 before any retry."})))

    def finish(self, request_id, response):
        with self.connect() as db:
            db.execute("UPDATE requests SET response=? WHERE id=?", (json.dumps(response), request_id))

    def get(self, key, default=None):
        with self.connect() as db:
            row = db.execute("SELECT value FROM state WHERE key=?", (key,)).fetchone()
        return json.loads(row[0]) if row else default

    def set(self, key, value):
        with self.connect() as db:
            db.execute("INSERT OR REPLACE INTO state VALUES (?, ?)", (key, json.dumps(value)))

    def unresolved(self):
        with self.connect() as db:
            return any(json.loads(row[0])["status"] in ("unknown", "partial") for row in db.execute("SELECT response FROM requests"))
