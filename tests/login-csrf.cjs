const fs = require('fs');
const assert = require('assert/strict');
const source = fs.readFileSync('public/app.js', 'utf8');
class DCLogic {}
const windowStub = {KITA_AUTH: {csrfToken: 'expired', loginUrl: '/login'}};
const requests = [];
let responses = [];
const fetchStub = async (url, options) => {
  requests.push({url, options});
  const [status, body] = responses.shift();
  return {status, ok: status === 200, json: async () => body};
};
const Component = new Function('DCLogic', 'window', 'fetch', 'React', source + '\nreturn Component;')(DCLogic, windowStub, fetchStub, {createElement: () => ({})});
const app = new Component();
app.handleSessionResponse = () => {};
(async () => {
  responses = [[419, {}], [200, {csrf_token: 'fresh'}], [200, {otp_required: true}]];
  assert.equal((await app.authPost('/login', {email: 'user@example.com'})).otp_required, true);
  assert.equal(requests.length, 3);
  assert.equal(requests[1].url, '/auth/csrf-token');
  assert.equal(requests[2].options.headers['X-CSRF-TOKEN'], 'fresh');
  responses = [[419, {}], [200, {csrf_token: 'another'}], [419, {message: 'CSRF token mismatch.'}]];
  await assert.rejects(() => app.authPost('/login', {}), /CSRF/);
  responses = [[419, {message: 'CSRF token mismatch.'}]];
  const before = requests.length;
  await assert.rejects(() => app.authPost('/api/transactions', {}), /CSRF/);
  assert.equal(requests.length, before + 1);
  console.log('Login CSRF recovery passed: refreshed token, one retry, no transaction replay.');
})().catch(error => {console.error(error); process.exitCode = 1;});
