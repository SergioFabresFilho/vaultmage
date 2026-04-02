import Constants from 'expo-constants';

function normalizeBaseUrl(url: string) {
  return url.trim().replace(/\/+$/, '');
}

function resolveExpoHost() {
  const hostUri = Constants.expoConfig?.hostUri;
  const host = hostUri?.split(':')[0]?.trim();

  if (!host || host === 'localhost' || host === '127.0.0.1') {
    return null;
  }

  return host;
}

function resolveApiBaseUrl() {
  const configuredUrl = process.env.EXPO_PUBLIC_API_BASE_URL;
  if (configuredUrl) {
    return normalizeBaseUrl(configuredUrl);
  }

  const expoHost = resolveExpoHost();
  if (expoHost) {
    return `http://${expoHost}:8000`;
  }

  return 'http://localhost:8000';
}

export const API_BASE_URL = resolveApiBaseUrl();

export const API_CONNECTION_HELP = [
  `VaultMage could not reach ${API_BASE_URL}.`,
  'If you are testing on a phone, run the Laravel API on your computer with `php artisan serve --host 0.0.0.0 --port 8000` and set `EXPO_PUBLIC_API_BASE_URL` to `http://<your-computer-lan-ip>:8000` when auto-detection is not available.',
].join('\n\n');
