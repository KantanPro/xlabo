#!/bin/bash

# xLabo プラグイン リリース用ZIPファイル作成スクリプト
# 使用方法: ./create_release_zip.sh

PLUGIN_NAME="xLabo"
VERSION=$(grep "Version:" xLabo.php | sed 's/.*Version: //' | tr -d ' ')
TODAY=$(date +%Y%m%d)
RELEASE_NAME="${PLUGIN_NAME}_${VERSION}_${TODAY}"

echo "=== xLabo プラグイン リリースZIP作成 ==="
echo "プラグイン名: ${PLUGIN_NAME}"
echo "バージョン: ${VERSION}"
echo "本日の日付: ${TODAY}"
echo "リリース名: ${RELEASE_NAME}"
echo ""

TARGET_DIR="/Users/kantanpro/Desktop/xLabo_TEST_UP"
if [ -f "${TARGET_DIR}/${RELEASE_NAME}.zip" ]; then
    echo "既存のZIPファイルを削除中: ${TARGET_DIR}/${RELEASE_NAME}.zip"
    rm "${TARGET_DIR}/${RELEASE_NAME}.zip"
fi

TEMP_DIR="/tmp/${PLUGIN_NAME}"
if [ -d "${TEMP_DIR}" ]; then
    echo "一時ディレクトリを削除中: ${TEMP_DIR}"
    rm -rf "${TEMP_DIR}"
fi

echo "一時ディレクトリを作成中: ${TEMP_DIR}"
mkdir -p "${TEMP_DIR}"

echo "ファイルをコピー中..."
cp -r includes "${TEMP_DIR}/"
cp -r assets "${TEMP_DIR}/"
cp xLabo.php "${TEMP_DIR}/"
cp readme.txt "${TEMP_DIR}/"
cp uninstall.php "${TEMP_DIR}/"

echo "不要なファイルを除外中..."
find "${TEMP_DIR}" -name ".git*" -type d -exec rm -rf {} + 2>/dev/null || true
find "${TEMP_DIR}" -name ".DS_Store" -type f -delete 2>/dev/null || true
find "${TEMP_DIR}" -name "*.zip" -type f -delete 2>/dev/null || true
find "${TEMP_DIR}" -name "*.backup" -type f -delete 2>/dev/null || true
find "${TEMP_DIR}" -name "*.tmp" -type f -delete 2>/dev/null || true

echo "ZIPファイルを作成中: ${RELEASE_NAME}.zip"
cd /tmp
zip -r "${RELEASE_NAME}.zip" "${PLUGIN_NAME}/" > /dev/null

mkdir -p "${TARGET_DIR}"
mv "${RELEASE_NAME}.zip" "${TARGET_DIR}/"

echo "一時ディレクトリを削除中..."
rm -rf "${TEMP_DIR}"

echo ""
echo "=== 完了 ==="
echo "リリースZIPファイルが作成されました: ${TARGET_DIR}/${RELEASE_NAME}.zip"
echo "ファイルサイズ: $(du -h "${TARGET_DIR}/${RELEASE_NAME}.zip" | cut -f1)"
echo "解凍後のフォルダ名: ${PLUGIN_NAME}"
echo ""
echo "このZIPファイルをWordPressプラグインとしてアップロードできます。"
