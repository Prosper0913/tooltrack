#!/bin/bash
set -e

ZIP_PATH="$1"
if [ -z "$ZIP_PATH" ]; then
  echo "Usage: ./apply_update.sh /path/to/tooltrack_updated.zip"
  exit 1
fi

echo "== Extracting =="
rm -rf _incoming
mkdir _incoming
unzip -q "$ZIP_PATH" -d _incoming

echo "== Copying files over the project =="
cp -rf _incoming/tooltrack/. ./
rm -rf _incoming

echo "== git status =="
git status

echo ""
echo "== git diff (press q to exit the pager) =="
git --no-pager diff --stat
echo ""
git diff

echo ""
read -p "Review looks good — commit and push? (y/n) " CONFIRM
if [ "$CONFIRM" != "y" ]; then
  echo "Stopped. Nothing committed. Your working directory still has the changes — review manually and run 'git add -A && git commit -m \"...\" && git push' yourself when ready."
  exit 0
fi

read -p "Commit message: " MSG
git add -A
git commit -m "$MSG"
git push

echo "Done."

# bash apply_update.sh ~/Downloads/tooltrack_updated.zip

# dance@ASUS-TUF-GAMING-F15 MINGW64 /c/xampp1/htdocs/tooltrack (main)
# $ bash apply_update.sh ~/Downloads/tooltrack_updated.zip
# == Extracting ==
# == Copying files over the project ==
# == git status ==
# On branch main
# Your branch is up to date with 'origin/main'.

# Changes not staged for commit:
#   (use "git add <file>..." to update what will be committed)
#   (use "git restore <file>..." to discard changes in working directory)
#         modified:   FPST LOGO for sidebar.png
#         modified:   api/auth.php
#         modified:   api/bootstrap.php
#         modified:   index.php
#         modified:   js/app.js
#         modified:   login_usa.html

# Untracked files:
#   (use "git add <file>..." to include in what will be committed)
#         apply_update.sh

# no changes added to commit (use "git add" and/or "git commit -a")

# == git diff (press q to exit the pager) ==
# warning: in the working copy of 'api/auth.php', LF will be replaced by CRLF the next time Git touches it
# warning: in the working copy of 'api/bootstrap.php', LF will be replaced by CRLF the next time Git touches it
# warning: in the working copy of 'index.php', LF will be replaced by CRLF the next time Git touches it
# warning: in the working copy of 'js/app.js', LF will be replaced by CRLF the next time Git touches it
# warning: in the working copy of 'login_usa.html', LF will be replaced by CRLF the next time Git touches it
#  FPST LOGO for sidebar.png | Bin 465274 -> 1471258 bytes
#  api/auth.php              |  72 +++++++++++++-------
#  api/bootstrap.php         |  13 ++++
#  index.php                 |  61 +++++++++++++----
#  js/app.js                 | 169 ++++++++++++++++++++++++++++++++++++++++++++--
#  login_usa.html            |   2 +-
#  6 files changed, 271 insertions(+), 46 deletions(-)

# warning: in the working copy of 'api/auth.php', LF will be replaced by CRLF the next time Git touches it
# warning: in the working copy of 'api/bootstrap.php', LF will be replaced by CRLF the next time Git touches it
# warning: in the working copy of 'index.php', LF will be replaced by CRLF the next time Git touches it
# warning: in the working copy of 'js/app.js', LF will be replaced by CRLF the next time Git touches it
# warning: in the working copy of 'login_usa.html', LF will be replaced by CRLF the next time Git touches it
# diff --git a/FPST LOGO for sidebar.png b/FPST LOGO for sidebar.png
# index 49c85b6..c6391aa 100644
# Binary files a/FPST LOGO for sidebar.png and b/FPST LOGO for sidebar.png differ
# diff --git a/api/auth.php b/api/auth.php
# index 0af5b24..8346276 100644
# --- a/api/auth.php
# +++ b/api/auth.php
# @@ -2,8 +2,9 @@
#  // ================================================================
#  //  api/auth.php
#  //
# -//  GET  → returns logged-in user info for sidebar
# :