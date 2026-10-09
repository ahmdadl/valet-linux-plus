/** Small formatting helpers shared by every page. */

/** Join class names, dropping falsy values. */
export function cx(...values: Array<string | false | null | undefined>): string {
  return values.filter(Boolean).join(' ');
}

/** "just now" / "4 min ago" / a date, for job and log timestamps. */
export function timeAgo(iso: string | null | undefined): string {
  if (!iso) {
    return '—';
  }

  const then = new Date(iso).getTime();

  if (Number.isNaN(then)) {
    return iso;
  }

  const seconds = Math.round((Date.now() - then) / 1000);

  if (seconds < 45) {
    return 'just now';
  }

  const minutes = Math.round(seconds / 60);

  if (minutes < 60) {
    return `${minutes} min ago`;
  }

  const hours = Math.round(minutes / 60);

  if (hours < 24) {
    return `${hours} h ago`;
  }

  const days = Math.round(hours / 24);

  if (days < 30) {
    return `${days} d ago`;
  }

  return new Date(then).toLocaleDateString();
}

/** "12:04:33" style stamp for log lines and job rows. */
export function clockTime(iso: string | null | undefined): string {
  if (!iso) {
    return '—';
  }

  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleTimeString();
}

/** Human byte size, matching the PHP `human` fields. */
export function bytes(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return '—';
  }

  if (value < 1024) {
    return `${value} B`;
  }

  const units = ['KB', 'MB', 'GB', 'TB'];
  let size = value / 1024;
  let unit = 0;

  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024;
    unit += 1;
  }

  return `${size < 10 ? size.toFixed(1) : Math.round(size)} ${units[unit]}`;
}

/** Shorten a home-relative path so long site paths stay readable. */
export function shortenPath(path: string): string {
  if (!path) {
    return '—';
  }

  return path.replace(/^\/home\/[^/]+/, '~');
}

/** Title-case a slug such as `mailpit` or `php8.2-fpm` for display. */
export function humanizeName(name: string): string {
  if (!name) {
    return '—';
  }

  return name
    .replace(/[-_.]/g, ' ')
    .replace(/\bphp\b/i, 'PHP')
    .replace(/\bfpm\b/i, 'FPM')
    .replace(/\bmysql\b/i, 'MySQL')
    .replace(/\bpostgres\b/i, 'PostgreSQL')
    .replace(/\bredis\b/i, 'Redis')
    .replace(/\bnginx\b/i, 'Nginx')
    .replace(/\bdnsmasq\b/i, 'DnsMasq')
    .replace(/\bmailpit\b/i, 'Mailpit')
    .replace(/\bhttps?\b/gi, (match) => match.toUpperCase())
    .replace(/\b\w/g, (match) => match.toUpperCase());
}

/** Filter a list of strings by a case-insensitive substring. */
export function matches(haystack: string | null | undefined, needle: string): boolean {
  if (!needle.trim()) {
    return true;
  }

  return (haystack ?? '').toLowerCase().includes(needle.trim().toLowerCase());
}
