import ftplib
import io
import os
from pathlib import Path, PurePosixPath
import time


LOCAL_ROOT = Path.cwd()
REMOTE_ROOT = PurePosixPath("/Feedback")
RUNTIME_ROOTS = (
    ".htaccess",
    ".env",
    "404.php",
    "500.php",
    "feedback_submitted.php",
    "home_page.php",
    "index.php",
    "login.php",
    "qr-entry.php",
    "admin",
    "api",
    "assets",
    "css",
    "form",
    "includes",
    "js",
    "lang",
    "modules",
    "vendor",
)
MAX_ATTEMPTS = 5


def required_environment(name):
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"Required deployment variable {name} is missing.")
    return value


FTP_SERVER = required_environment("FTP_SERVER")
FTP_USERNAME = required_environment("FTP_USERNAME")
FTP_PASSWORD = required_environment("FTP_PASSWORD")
FTP_PORT = int(os.environ.get("FTP_PORT", "21"))


def connect():
    ftp = ftplib.FTP(timeout=60)
    ftp.connect(FTP_SERVER, FTP_PORT)
    ftp.login(FTP_USERNAME, FTP_PASSWORD)
    ftp.set_pasv(True)
    return ftp


def runtime_files():
    files = []
    for item in RUNTIME_ROOTS:
        path = LOCAL_ROOT / item
        if path.is_file():
            files.append(path)
        elif path.is_dir():
            files.extend(
                candidate
                for candidate in path.rglob("*")
                if candidate.is_file() and ".git" not in candidate.parts
            )
    return sorted(files)


def ensure_directory(ftp, path):
    current = PurePosixPath("/")
    for part in path.parts:
        if part == "/":
            continue
        current /= part
        try:
            ftp.mkd(str(current))
        except ftplib.error_perm as error:
            if not str(error).startswith("550"):
                raise


def deploy_file(ftp, local_path, remote_path):
    try:
        remote_size = ftp.size(str(remote_path))
    except ftplib.all_errors:
        remote_size = None

    if remote_size == local_path.stat().st_size:
        return False

    with local_path.open("rb") as source:
        ftp.storbinary(f"STOR {remote_path}", source)
    return True


def root_redirect():
    content = b"<?php\nheader('Location: /Feedback/', true, 302);\nexit;\n"
    return io.BytesIO(content)


files = runtime_files()
ftp = connect()
uploaded = 0
skipped = 0

try:
    ensure_directory(ftp, REMOTE_ROOT)
    created = set()

    for local_path in files:
        relative = local_path.relative_to(LOCAL_ROOT).as_posix()
        remote_path = REMOTE_ROOT / relative
        parent = remote_path.parent

        for attempt in range(1, MAX_ATTEMPTS + 1):
            try:
                if parent not in created:
                    ensure_directory(ftp, parent)
                    created.add(parent)
                if deploy_file(ftp, local_path, remote_path):
                    uploaded += 1
                else:
                    skipped += 1
                break
            except (OSError, EOFError, ftplib.Error):
                try:
                    ftp.close()
                except OSError:
                    pass
                if attempt == MAX_ATTEMPTS:
                    raise
                time.sleep(attempt * 2)
                ftp = connect()

    ftp.storbinary("STOR /index.php", root_redirect())
finally:
    try:
        ftp.quit()
    except (OSError, EOFError, ftplib.Error):
        ftp.close()

print(f"Uploaded files: {uploaded}")
print(f"Unchanged files: {skipped}")
print("Production path: /Feedback/")
