// End-to-end test of SMSTunnel for Magento against a local SMSTunnel API (dev only, never production).
//
//   SMSTUNNEL_KEY_FILE=/path/to/local-test-key.json STACK=smst-int-magento249 WEB_PORT=8476 node dev/e2e/e2e.mjs
//
// The key file holds { serverUrl, apiKey, deviceId } for a local test server; the key is never printed.
// Magento runs in the Docker stack of dev/docker. Every text is checked in the local SMSTunnel database (read-only)
// by the message ID the module recorded, so other clients of a shared test server do not disturb the checks.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const STACK = process.env.STACK || 'smst-int-magento249';
const PORT = process.env.WEB_PORT || '8476';
const BASE = `http://127.0.0.1:${PORT}`;
const PHP = `${STACK}-php-1`;
const DB = `${STACK}-db-1`;
const MONGO = process.env.SMSTUNNEL_MONGO || 'mongodb://127.0.0.1:27018/smstunnel_app_dev';
const key = JSON.parse(readFileSync(process.env.SMSTUNNEL_KEY_FILE, 'utf8'));
if (!/^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(key.serverUrl)) {
  throw new Error('This test only runs against a local SMSTunnel server.');
}
const SERVER_IN_DOCKER = key.serverUrl.replace(/127\.0\.0\.1|localhost/, 'host.docker.internal');
// The PHP container's own loopback: nothing listens there, so the connection is refused at once.
const DOWN_SERVER = 'http://127.0.0.1:9';
const SKU = 'smst-e2e-tee';
// Recipients unique to this stack: +1 415 555 <last two digits of the port><n>.
const P = PORT.slice(-2);
const local = (n) => `(415) 555-${P}${n}`;
const N = {
  customer: `+1415555${P}61`, owner: `+1415555${P}62`, second: `+1415555${P}63`,
  cancel: `+1415555${P}64`, hold: `+1415555${P}65`, down: `+1415555${P}66`,
};
const startedAt = new Date(Date.now() - 2000);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

let passes = 0;
let failures = 0;
function check(name, ok, detail) {
  if (ok) {
    passes += 1;
    console.log(`PASS  ${name}`);
  } else {
    failures += 1;
    console.log(`FAIL  ${name}${detail === undefined ? '' : `\n      ${JSON.stringify(detail).slice(0, 900)}`}`);
  }
}

function magento(...args) {
  return execFileSync('docker', ['exec', '-u', 'www-data', PHP, 'php', '-d', 'memory_limit=-1', 'bin/magento', ...args], {
    encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
  });
}
function php(...args) {
  return execFileSync('docker', ['exec', '-u', 'www-data', PHP, 'php', ...args], { encoding: 'utf8' });
}
function sql(query) {
  return execFileSync('docker', ['exec', DB, 'mariadb', '-umagento', '-pmagento', 'magento', '-N', '-B', '-r', '-e', query], {
    encoding: 'utf8',
  }).split('\n').filter(Boolean);
}
function texts(where) {
  return sql(`SELECT JSON_OBJECT('id', entity_id, 'event', event, 'order', order_increment_id, 'to', recipient,
      'text', message, 'status', status, 'attempts', attempts, 'details', details, 'mid', smstunnel_message_id,
      'next', next_attempt_at, 'store', store_id) FROM smstunnel_message WHERE ${where} ORDER BY entity_id`)
    .map((line) => JSON.parse(line));
}
function config(path, value, scope) {
  const scopeArgs = scope ? ['--scope=stores', `--scope-code=${scope}`] : [];
  magento('config:set', ...scopeArgs, path, value);
}
function consume() {
  return magento('queue:consumers:start', 'smstunnel.message.send', '--single-thread', '--max-messages=100');
}
// Runs the consumer; when the shared test server asks to slow down (429), waits out its one-minute window and
// lets the module's own retry job (smstunnel_send_pending) send the texts, as Magento's cron would later on.
// Each forced retry is a real attempt, and the module gives up after 5, so they are spaced 35 s apart.
async function drain(where) {
  consume();
  for (let i = 0; i < 4; i += 1) {
    const waiting = texts(`${where} AND status = 'pending'`);
    if (waiting.length === 0 || !waiting.every((r) => /answered 429/.test(r.details || ''))) {
      return;
    }
    console.log(`      (the shared test server asked to slow down: retrying ${waiting.length} text(s) in 35 s)`);
    await sleep(35000);
    sql(`UPDATE smstunnel_message SET next_attempt_at = UTC_TIMESTAMP() WHERE ${where} AND status = 'pending'`);
    php('/module/dev/e2e/send-pending.php');
  }
}

async function rest(method, path, body, { token, store = 'default' } = {}) {
  const res = await fetch(`${BASE}/rest/${store}/V1/${path}`, {
    method,
    headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const raw = await res.text();
  let data;
  try {
    data = JSON.parse(raw);
  } catch {
    data = raw;
  }
  if (!res.ok) {
    throw new Error(`${method} ${path}: ${res.status} ${raw.slice(0, 400)}`);
  }
  return data;
}

async function guestOrder(phone, store = 'default') {
  const cartId = await rest('POST', 'guest-carts', undefined, { store });
  await rest('POST', `guest-carts/${cartId}/items`, { cartItem: { sku: SKU, qty: 1, quote_id: cartId } }, { store });
  const address = {
    firstname: 'Ana', lastname: 'Pop', street: ['1 Market St'], city: 'San Francisco', region: 'California',
    region_id: 12, region_code: 'CA', postcode: '94105', country_id: 'US', telephone: phone, email: 'ana@example.com',
  };
  await rest('POST', `guest-carts/${cartId}/shipping-information`, {
    addressInformation: {
      shipping_address: address, billing_address: address, shipping_carrier_code: 'flatrate', shipping_method_code: 'flatrate',
    },
  }, { store });
  const t0 = Date.now();
  const orderId = await rest('POST', `guest-carts/${cartId}/payment-information`, {
    email: 'ana@example.com', paymentMethod: { method: 'checkmo' }, billingAddress: address,
  }, { store });
  return { orderId: Number(orderId), ms: Date.now() - t0 };
}

const require = createRequire('D:/ANDROID/SMSserver/package.json');
const { MongoClient, ObjectId } = require('mongodb');
const mongo = new MongoClient(MONGO);
await mongo.connect();
const messages = mongo.db().collection('messages');

// The text SMSTunnel holds under the message ID the module recorded, once the phone has sent it.
async function textById(mid) {
  if (!mid || !/^[0-9a-f]{24}$/.test(mid)) {
    return null;
  }
  let doc = null;
  for (let i = 0; i < 20; i += 1) {
    doc = await messages.findOne({ _id: new ObjectId(mid) });
    if (doc && (doc.status === 'sent' || doc.status === 'delivered')) {
      break;
    }
    await sleep(500);
  }
  return doc;
}
async function checkSent(name, row) {
  const doc = await textById(row?.mid);
  check(name, !!doc && row.status === 'sent' && doc.phoneNumber === row.to && doc.message === row.text
    && String(doc.deviceId) === key.deviceId && ['sent', 'delivered'].includes(doc.status), {
    row: row && { event: row.event, to: row.to, status: row.status, details: row.details, mid: row.mid },
    smstunnel: doc && { to: doc.phoneNumber, status: doc.status, text: doc.message },
  });
}
async function countAtSmsTunnel(number, text) {
  return messages.countDocuments({ phoneNumber: number, message: text, createdAt: { $gte: startedAt } });
}

try {
  console.log(`Magento ${magento('--version').trim()} at ${BASE}, SMSTunnel at ${SERVER_IN_DOCKER}\n`);

  // Setup: settings at default scope, a second store view with its own template, a product.
  magento('setup:config:set', '--consumers-wait-for-messages=0', '-n');
  config('smstunnel/general/enabled', '1');
  config('smstunnel/general/api_key', key.apiKey);
  config('smstunnel/general/device_id', key.deviceId);
  config('smstunnel/advanced/server_url', SERVER_IN_DOCKER);
  config('smstunnel/owner/enabled', '1');
  config('smstunnel/owner/numbers', N.owner);
  config('smstunnel/invoice_created/enabled', '1');
  config('smstunnel/status_changed/enabled', '1');
  config('smstunnel/status_changed/statuses', 'holded');
  const storedKey = sql("SELECT value FROM core_config_data WHERE path = 'smstunnel/general/api_key' AND scope = 'default'")[0] || '';
  check('the API key is stored encrypted', /^\d+:\d+:/.test(storedKey) && !storedKey.includes(key.apiKey));
  const secondStoreId = Number(php('/module/dev/e2e/create-store.php').trim());
  check('store view "second" exists', secondStoreId > 1, secondStoreId);
  config('smstunnel/order_placed/template', 'Second view: order #{{order_id}} for {{customer_firstname}}.', 'second');
  magento('cache:flush');

  const token = await rest('POST', 'integration/admin/token', { username: 'admin', password: 'SmsTunnel123!' });
  try {
    await rest('GET', `products/${SKU}`, undefined, { token });
  } catch {
    await rest('POST', 'products', {
      product: {
        sku: SKU, name: 'SMSTunnel Tee', attribute_set_id: 4, price: 25, status: 1, visibility: 4, type_id: 'simple', weight: 1,
        extension_attributes: { website_ids: [1], stock_item: { qty: 1000, is_in_stock: true } },
      },
    }, { token, store: 'all' });
  }
  // Since 2.4.7 indexers update by schedule: index the new product now.
  magento('indexer:reindex');

  // 1. A guest order: texts are queued, checkout does not wait for SMSTunnel.
  const first = await guestOrder(local(61));
  const inc1 = (await rest('GET', `orders/${first.orderId}`, undefined, { token })).increment_id;
  console.log(`\nOrder ${inc1} placed in ${first.ms} ms`);
  let rows = texts(`order_id = ${first.orderId}`);
  check('order placed: customer and owner texts queued', rows.length === 2
    && rows.every((r) => r.status === 'pending' && r.mid === null), rows);
  check('customer number completed to E.164 from the US address', rows[0]?.to === N.customer, rows[0]);
  check('order placed text filled in', rows[0]?.text === `Hi Ana, thanks for your order #${inc1} at Main Website Store.`
    + ' Total: $30.00. We will text you when it ships.', rows[0]?.text);
  check('owner text filled in', rows[1]?.to === N.owner
    && rows[1]?.text === `New order #${inc1} at Main Website Store: $30.00 from Ana Pop.`, rows[1]);
  check('nothing reached SMSTunnel before the consumer ran', (await countAtSmsTunnel(N.customer, rows[0]?.text)) === 0);

  await drain(`order_id = ${first.orderId}`);
  rows = texts(`order_id = ${first.orderId}`);
  check('the queue consumer sent both texts', rows.every((r) => r.status === 'sent' && r.mid), rows);
  await checkSent('SMSTunnel has the customer text, sent by the phone', rows[0]);
  await checkSent('SMSTunnel has the owner text', rows[1]);

  // 2. Invoice, 3. shipment with tracking.
  await rest('POST', `order/${first.orderId}/invoice`, { capture: true, notify: false }, { token });
  await rest('POST', `order/${first.orderId}/ship`, {
    notify: false,
    tracks: [{ track_number: '1Z999AA10123456784', title: 'United Parcel Service', carrier_code: 'ups' }],
  }, { token });
  rows = texts(`order_id = ${first.orderId} AND event IN ('invoice_created', 'shipment_created')`);
  check('invoice and shipment texts queued', rows.map((r) => r.event).join(',') === 'invoice_created,shipment_created', rows);
  check('shipment text carries the tracking number', rows[1]?.text
    === `Good news, Ana: your order #${inc1} from Main Website Store is on its way.\nTracking number: 1Z999AA10123456784`,
  rows[1]?.text);
  await drain(`order_id = ${first.orderId}`);
  rows = texts(`order_id = ${first.orderId} AND event IN ('invoice_created', 'shipment_created')`);
  await checkSent('SMSTunnel has the invoice text', rows[0]);
  await checkSent('SMSTunnel has the shipment text', rows[1]);

  // 4. Canceled order.
  const second = await guestOrder(`415-555-${P}64`);
  await rest('POST', `orders/${second.orderId}/cancel`, undefined, { token });
  rows = texts(`order_id = ${second.orderId}`);
  check('canceled order: placed, owner and canceled texts', rows.map((r) => r.event).join(',')
    === 'order_placed,owner_new_order,order_canceled', rows.map((r) => r.event));
  await drain(`order_id = ${second.orderId}`);
  rows = texts(`order_id = ${second.orderId} AND event = 'order_canceled'`);
  check('canceled text filled in', /^Main Website Store: your order #\d+ has been canceled\.$/.test(rows[0]?.text || ''), rows);
  await checkSent('SMSTunnel has the canceled text', rows[0]);

  // 5. Status change to a picked status (On Hold).
  const third = await guestOrder(`+1 415 555 ${P}65`);
  await rest('POST', `orders/${third.orderId}/hold`, undefined, { token });
  rows = texts(`order_id = ${third.orderId} AND event = 'status_changed'`);
  check('status change text queued with the status label', rows.length === 1 && /is now On Hold\.$/.test(rows[0].text), rows);
  await rest('POST', `orders/${third.orderId}/unhold`, undefined, { token });
  check('no text for a status that was not picked', texts(`order_id = ${third.orderId} AND event = 'status_changed'`).length === 1);
  await drain(`order_id = ${third.orderId}`);
  await checkSent('SMSTunnel has the status text', texts(`order_id = ${third.orderId} AND event = 'status_changed'`)[0]);

  // 6. An invalid number is skipped; the order goes through.
  const invalid = await guestOrder('12');
  rows = texts(`order_id = ${invalid.orderId} AND event = 'order_placed'`);
  check('invalid number: text skipped with the reason', rows.length === 1 && rows[0].status === 'skipped'
    && /does not look like a phone number/.test(rows[0].details || ''), rows);
  await drain(`order_id = ${invalid.orderId}`);
  check('invalid number: still skipped after the consumer ran',
    texts(`order_id = ${invalid.orderId} AND event = 'order_placed'`)[0]?.status === 'skipped');

  // 7. SMSTunnel unreachable: checkout still works, the text is retried and goes out later.
  config('smstunnel/advanced/server_url', DOWN_SERVER);
  magento('cache:clean', 'config');
  const down = await guestOrder(local(66));
  console.log(`\nOrder placed in ${down.ms} ms while SMSTunnel was unreachable`);
  check('checkout works while SMSTunnel is unreachable', down.orderId > 0 && down.ms < 10000, down);
  consume();
  rows = texts(`order_id = ${down.orderId} AND event = 'order_placed'`);
  check('unreachable: the text waits for a retry with the error', rows[0]?.status === 'pending' && rows[0]?.attempts === 1
    && /Could not reach SMSTunnel/.test(rows[0]?.details || '') && rows[0]?.next !== null, rows);
  const log = execFileSync('docker', ['exec', PHP, 'sh', '-c', 'tail -n 30 /var/www/html/var/log/smstunnel.log'], { encoding: 'utf8' });
  check('unreachable: logged in var/log/smstunnel.log', log.includes(`text ${rows[0]?.id} will be tried again`), log.slice(-400));
  config('smstunnel/advanced/server_url', SERVER_IN_DOCKER);
  magento('cache:clean', 'config');
  consume();
  check('not retried before its time', texts(`order_id = ${down.orderId} AND event = 'order_placed'`)[0]?.status === 'pending');
  sql(`UPDATE smstunnel_message SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE order_id = ${down.orderId}`);
  magento('cron:run', '--group=default');
  await drain(`order_id = ${down.orderId}`);
  rows = texts(`order_id = ${down.orderId} AND event = 'order_placed'`);
  check('Magento cron retried and sent it', rows[0]?.status === 'sent' && rows[0]?.attempts >= 2, rows);
  await checkSent('SMSTunnel has the retried text', rows[0]);

  // 8. Store view scope: the "second" store view has its own template.
  const scoped = await guestOrder(local(63), 'second');
  rows = texts(`order_id = ${scoped.orderId} AND event = 'order_placed'`);
  check('store view template used for the second store view', rows[0]?.store === secondStoreId
    && /^Second view: order #\d+ for Ana\.$/.test(rows[0]?.text || ''), rows);
  await drain(`order_id = ${scoped.orderId}`);
  await checkSent('SMSTunnel has the store view text', texts(`order_id = ${scoped.orderId} AND event = 'order_placed'`)[0]);

  // 9. No duplicates: saving an order again, running the consumer and cron again.
  await rest('POST', `orders/${first.orderId}/comments`, {
    statusHistory: { comment: 'Packed', is_customer_notified: 0, is_visible_on_front: 0, parent_id: first.orderId },
  }, { token });
  consume();
  magento('cron:run', '--group=default');
  const firstRows = texts(`order_id = ${first.orderId}`);
  check('no duplicate rows after the order is saved again', firstRows.length === 4, firstRows.map((r) => r.event));
  let duplicates = 0;
  for (const row of firstRows) {
    duplicates += (await countAtSmsTunnel(row.to, row.text)) - 1;
  }
  check('each text reached SMSTunnel exactly once', duplicates === 0, duplicates);
  const totals = texts('created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR');
  console.log(`\nTexts in smstunnel_message this run: ${totals.length} (${totals.filter((r) => r.status === 'sent').length} sent, `
    + `${totals.filter((r) => r.status === 'skipped').length} skipped, ${totals.filter((r) => r.status === 'pending').length} waiting)`);
} catch (error) {
  failures += 1;
  console.log(`FAIL  the run stopped: ${error.stack || error}`);
} finally {
  await mongo.close();
}

console.log(`\n${passes} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
