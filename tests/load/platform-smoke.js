import http from 'k6/http';
import { check, sleep } from 'k6';

const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');

if (!baseUrl) {
  throw new Error('Set BASE_URL to the HTTPS staging application URL.');
}
if (!baseUrl.startsWith('https://')) {
  throw new Error('This load harness only permits HTTPS targets.');
}

export const options = {
  stages: [
    { duration: '30s', target: 5 },
    { duration: '1m', target: 5 },
    { duration: '30s', target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1000'],
    checks: ['rate>0.99'],
  },
};

const paths = ['/up', '/api/v1/health', '/health/ready'];

export default function () {
  for (const path of paths) {
    const response = http.get(`${baseUrl}${path}`, {
      timeout: '10s',
      tags: { endpoint: path },
    });

    check(response, {
      [`${path} returns ready status`]: (res) => res.status === 200,
    });
  }

  // Keep the shared-IP request rate below the readiness route's 30/min limit.
  sleep(15);
}
