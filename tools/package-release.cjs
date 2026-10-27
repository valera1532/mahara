'use strict';
// Produce a clean upload directory. Never ship local credentials or databases.
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const stamp = new Date().toISOString().replace(/[:.]/g, '-');
const destination = path.join(root, '.local', 'releases', `mahara-reg-${stamp}`);
const site = path.join(destination, 'site');
fs.mkdirSync(path.join(site, 'server', 'templates'), {recursive:true});
function copy(relative) {
  const source = path.join(root, relative);
  const target = path.join(site, relative);
  fs.mkdirSync(path.dirname(target), {recursive:true});
  fs.copyFileSync(source, target);
}
for (const file of ['index.php','.htaccess','.user.ini','styles.css','legal.css','script.js','catalog-data.js','cms-data.js','admin.css','admin.js','admin-login.js','inventory.css','inventory.js','management.css','checkout.css','checkout.js','orders.css','orders.js','operations.css','operations.js','product-gallery.css','product-gallery.js','site-defaults.json','seo.config.json']) copy(file);
for (const file of fs.readdirSync(path.join(root, 'server'))) {
  if (file.endsWith('.php') && file !== 'config.local.php' && file !== 'router.php') copy(`server/${file}`);
}
for (const file of fs.readdirSync(root).filter(file => file.endsWith('.html'))) {
  fs.copyFileSync(path.join(root, file), path.join(site, 'server', 'templates', `${file}.php`));
}
copy('server/templates/admin-payment-setup.php');
copy('server/templates/admin-shipping-setup.php');
for (const directory of ['images','icons']) {
  for (const file of fs.readdirSync(path.join(root,'assets',directory))) {
    if (/\.(?:webp|png|jpe?g|svg|ico)$/i.test(file)) copy(`assets/${directory}/${file}`);
  }
}
const fonts = path.join(root, 'assets', 'fonts');
if (fs.existsSync(fonts)) {
  if (fs.lstatSync(fonts).isSymbolicLink()) throw new Error('Font assets must be a regular directory.');
  for (const entry of fs.readdirSync(fonts, {withFileTypes:true})) {
    if (entry.isFile() && (/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,199}\.woff2$/.test(entry.name)
      || ['fonts.css', 'OFL-Cormorant-Garamond.txt', 'OFL-Manrope.txt', 'SOURCE.json'].includes(entry.name))) copy(`assets/fonts/${entry.name}`);
  }
}
copy('tools/admin.php');
copy('tools/commerce.php');
copy('tools/tbank.php');
copy('tools/notifications.php');
copy('tools/telegram.php');
copy('tools/telegram-menu.php');
copy('tools/backup.php');
copy('server/certs/tbank-ca.pem');
for (const file of ['swiper-bundle.min.js', 'swiper-bundle.min.css', 'LICENSE', 'SOURCE.json']) copy(`assets/vendor/swiper/${file}`);
fs.copyFileSync(path.join(root,'server','config.local.php.example'),path.join(site,'server','config.local.php.example'));
fs.copyFileSync(path.join(root,'DEPLOYMENT.md'),path.join(destination,'DEPLOYMENT.md'));
fs.writeFileSync(path.join(destination,'MANIFEST.json'),JSON.stringify({createdAt:new Date().toISOString(),target:'REG.RU Host-0 / PHP 8.2+',containsCredentials:false,containsDatabase:false,templatesPublic:false},null,2)+'\n');
console.log(destination);
