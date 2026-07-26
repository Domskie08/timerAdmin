#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${DTIMER_VERSION:-1.0.0}"
PACKAGE_NAME="dtimer-orange-pi"
BUILD_DIR="${ROOT_DIR}/build/deb"
PKG_DIR="${BUILD_DIR}/${PACKAGE_NAME}_${VERSION}_all"
OUT_DIR="${ROOT_DIR}/dist"

rm -rf "${BUILD_DIR}"
mkdir -p "${PKG_DIR}/DEBIAN" \
  "${PKG_DIR}/opt/dtimer-orange-pi" \
  "${PKG_DIR}/etc/dtimer-orange-pi" \
  "${PKG_DIR}/etc/systemd/system" \
  "${PKG_DIR}/var/lib/dtimer-orange-pi" \
  "${PKG_DIR}/var/log/dtimer-orange-pi" \
  "${OUT_DIR}"

cd "${ROOT_DIR}"
if [[ -f package-lock.json ]]; then
  npm ci
else
  npm install
fi
npm run build

cp -R app.py dtimer_device frontend scripts "${PKG_DIR}/opt/dtimer-orange-pi/"
find "${PKG_DIR}/opt/dtimer-orange-pi" -type d -name __pycache__ -prune -exec rm -rf {} +
find "${PKG_DIR}/opt/dtimer-orange-pi" -type f -name '*.pyc' -delete

cp packaging/dtimer.conf "${PKG_DIR}/etc/dtimer-orange-pi/dtimer.conf"
cp packaging/dtimer-orange-pi.service "${PKG_DIR}/etc/systemd/system/dtimer-orange-pi.service"
cp packaging/debian/control "${PKG_DIR}/DEBIAN/control"
cp packaging/debian/conffiles "${PKG_DIR}/DEBIAN/conffiles"
cp packaging/debian/postinst "${PKG_DIR}/DEBIAN/postinst"
cp packaging/debian/prerm "${PKG_DIR}/DEBIAN/prerm"
cp packaging/debian/postrm "${PKG_DIR}/DEBIAN/postrm"

sed -i "s/^Version: .*/Version: ${VERSION}/" "${PKG_DIR}/DEBIAN/control"

chmod 0755 "${PKG_DIR}/DEBIAN/postinst" "${PKG_DIR}/DEBIAN/prerm" "${PKG_DIR}/DEBIAN/postrm"
chmod 0755 "${PKG_DIR}/opt/dtimer-orange-pi/app.py"
chmod 0755 "${PKG_DIR}/opt/dtimer-orange-pi/scripts/apply_network_rules.sh"
chmod 0644 "${PKG_DIR}/etc/dtimer-orange-pi/dtimer.conf"
chmod 0644 "${PKG_DIR}/etc/systemd/system/dtimer-orange-pi.service"

dpkg-deb --build --root-owner-group "${PKG_DIR}" "${OUT_DIR}/${PACKAGE_NAME}_${VERSION}_all.deb"
echo "Built ${OUT_DIR}/${PACKAGE_NAME}_${VERSION}_all.deb"
