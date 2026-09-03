// Run a command on the deploy server. usage: node dssh.js "<remote command>"
// Password comes from the DEPLOY_PASS env var.
const { Client } = require('/tmp/node_modules/ssh2');
const c = new Client();
const cmd = process.argv[2];
c.on('ready', () => {
  c.exec(cmd, (err, stream) => {
    if (err) { console.error(err); process.exit(1); }
    stream.on('close', (code) => { c.end(); process.exit(code || 0); })
      .on('data', d => process.stdout.write(d))
      .stderr.on('data', d => process.stderr.write(d));
  });
}).connect({
  host: process.env.DEPLOY_HOST || '207.180.253.8',
  username: 'root',
  password: process.env.DEPLOY_PASS,
  readyTimeout: 20000,
});
