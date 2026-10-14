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
for (const file of ['index.php','.htaccess','.user.ini','styles.css','legal.css','script.js','catalog-data.js','cms-data.js','admin.css','admin.js','admin-login.js','inventory.css','inventory.js','management.css','checkout.css','checkout.js','orders.css','orders.js','site-defaults.json','seo.config.json']) copy(file);
for (const file of fs.readdirSync(path.join(root, 'server'))) {
  if (file.endsWith('.php') && file !== 'config.local.php' && file !== 'router.php') copy(`server/${file}`);
}
for (const file of fs.readdirSync(root).filter(file => file.endsWith('.html'))) {
  fs.copyFileSync(path.join(root, file), path.join(site, 'server', 'templates', `${file}.php`));
}
for (const directory of ['images','icons']) {
  for (const file of fs.readdirSync(path.join(root,'assets',directory))) {
    if (/\.(?:webp|png|jpe?g|svg|ico)$/i.test(file)) copy(`assets/${directory}/${file}`);
  }
}
copy('tools/admin.php');
copy('tools/commerce.php');
copy('tools/tbank.php');
copy('tools/notifications.php');
copy('tools/backup.php');
copy('server/certs/tbank-ca.pem');
fs.copyFileSync(path.join(root,'server','config.local.php.example'),path.join(site,'server','config.local.php.example'));
fs.copyFileSync(path.join(root,'DEPLOYMENT.md'),path.join(destination,'DEPLOYMENT.md'));
fs.writeFileSync(path.join(destination,'MANIFEST.json'),JSON.stringify({createdAt:new Date().toISOString(),target:'REG.RU Host-0 / PHP 8.2+',containsCredentials:false,containsDatabase:false,templatesPublic:false},null,2)+'\n');
console.log(destination);
