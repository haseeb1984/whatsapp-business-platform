import http from 'k6/http';
import { check } from 'k6';

const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');

if (!baseUrl) {
  throw new Error('Set BASE_URL to the HTTPS staging application URL.');
}
if (!baseUrl.startsWith('https://')) {
  throw new Error('This abuse probe only permits HTTPS targets.');
}

export const options = {
  vus: 1,
  iterations: 31,
  thresholds: {
    checks: ['rate==1'],
  },
};

export default function () {
  const response = http.get(`${baseUrl}/health/ready`, {
    timeout: '10s',
    tags: { endpoint: '/health/ready', test: 'rate-limit' },
  });

  const expectedStatus = __ITER < 30 ? 200 : 429;
  check(response, {
    [`request ${__ITER + 1} returns ${expectedStatus}`]: (res) => res.status === expectedStatus,
  });
}
