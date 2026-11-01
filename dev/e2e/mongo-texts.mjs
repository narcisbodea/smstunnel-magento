// Read-only: lists texts the local SMSTunnel API accepted since a time, for some numbers.
// Usage: node mongo-texts.mjs <sinceISO> <number> [<number> ...]
import { createRequire } from 'node:module';

const require = createRequire('D:/ANDROID/SMSserver/package.json');
const { MongoClient } = require('mongodb');

const [since, ...numbers] = process.argv.slice(2);
const client = new MongoClient('mongodb://127.0.0.1:27018/smstunnel_app_dev', { readPreference: 'secondaryPreferred' });
await client.connect();
try {
  const filter = { createdAt: { $gte: new Date(since || 0) } };
  if (numbers.length) filter.phoneNumber = { $in: numbers };
  const docs = await client.db().collection('messages')
    .find(filter, { projection: { phoneNumber: 1, message: 1, status: 1, createdAt: 1, deviceId: 1 } })
    .sort({ createdAt: 1 }).toArray();
  console.log(JSON.stringify(docs.map((d) => ({
    id: String(d._id), to: d.phoneNumber, status: d.status, text: d.message, at: d.createdAt,
  })), null, 1));
} finally {
  await client.close();
}
