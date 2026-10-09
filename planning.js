(function (root) {
  'use strict';
  const pad = n => String(n).padStart(2, '0');
  const zone = () => Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  function localInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (!Number.isFinite(d.getTime())) return '';
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }
  function schedule(start, end) {
    if (!start && !end) return { scheduledStart: '', scheduledEnd: '', scheduleTimeZone: zone() };
    const dates = [start, end].map(value => {
      if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) throw new Error('Indica fecha y hora de inicio y fin.');
      const date = new Date(value);
      // Reject invalid calendar dates and times skipped by a DST transition.
      if (!Number.isFinite(date.getTime()) || localInput(date.toISOString()) !== value) throw new Error('La fecha u hora no existe en tu zona horaria.');
      return date;
    });
    if (dates[1] <= dates[0]) throw new Error('El fin debe ser posterior al inicio.');
    return { scheduledStart: dates[0].toISOString(), scheduledEnd: dates[1].toISOString(), scheduleTimeZone: zone() };
  }
  function timeline(tasks, project = '') {
    const visible = tasks.filter(t => t.list !== 'trash' && (!project || (project === '__none__' ? !t.project : t.project === project)));
    const scheduled = visible.filter(t => t.scheduledStart && t.scheduledEnd && Date.parse(t.scheduledEnd) > Date.parse(t.scheduledStart))
      .sort((a, b) => Date.parse(a.scheduledStart) - Date.parse(b.scheduledStart) || a.title.localeCompare(b.title));
    const unscheduled = visible.filter(t => !scheduled.includes(t));
    const min = scheduled.length ? Math.min(...scheduled.map(t => Date.parse(t.scheduledStart))) : 0;
    const max = scheduled.length ? Math.max(...scheduled.map(t => Date.parse(t.scheduledEnd))) : 0;
    return { scheduled, unscheduled, min, max };
  }
  // Calendar boundaries use local dates, not multiples of 24h: a DST day can
  // contain 23 or 25 hours. Geometry uses the actual elapsed time throughout.
  function calendar(mode = 'week', anchor = Date.now(), now = Date.now()) {
    mode = ['day', 'week', 'month'].includes(mode) ? mode : 'week';
    const date = new Date(anchor);
    if (!Number.isFinite(date.getTime())) return calendar(mode, now, now);
    const start = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    if (mode === 'week') start.setDate(start.getDate() - (start.getDay() + 6) % 7);
    if (mode === 'month') start.setDate(1);
    const end = new Date(start);
    if (mode === 'month') end.setMonth(end.getMonth() + 1);
    else end.setDate(end.getDate() + (mode === 'day' ? 1 : 7));
    const columns = [];
    for (let cursor = new Date(start); cursor < end;) {
      const next = new Date(cursor);
      if (mode === 'day') next.setTime(next.getTime() + 3600000);
      else next.setDate(next.getDate() + 1);
      if (next > end) next.setTime(+end);
      const today = cursor.toDateString() === new Date(now).toDateString();
      const timeOptions = { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };
      // Offset disambiguates the repeated hour in an autumn DST transition.
      if (mode === 'day' && end - start !== 86400000) timeOptions.timeZoneName = 'shortOffset';
      columns.push({ start: +cursor, end: +next, today,
        label: mode === 'day' ? cursor.toLocaleTimeString('es-MX', timeOptions) : String(cursor.getDate()),
        sublabel: mode === 'day' ? '' : cursor.toLocaleDateString('es-MX', { weekday: 'short' }),
        left: (+cursor - start) / (end - start) * 100,
        width: (next - cursor) / (end - start) * 100,
        weekend: cursor.getDay() === 0 || cursor.getDay() === 6
      });
      cursor = next;
    }
    return { mode, start: +start, end: +end, columns,
      today: now >= start && now < end ? (now - start) / (end - start) * 100 : null,
      width: Math.max(700, columns.length * (mode === 'week' ? 100 : mode === 'day' ? 58 : 46))
    };
  }
  function shiftCalendar(mode, anchor, direction) {
    const range = calendar(mode, anchor), date = new Date(range.start);
    if (range.mode === 'month') date.setMonth(date.getMonth() + direction);
    else date.setDate(date.getDate() + direction * (range.mode === 'day' ? 1 : 7));
    return +date;
  }
  function calendarTasks(tasks, range) {
    const visible = [], outside = [];
    tasks.forEach(task => {
      const start = Date.parse(task.scheduledStart), end = Date.parse(task.scheduledEnd);
      if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) return;
      // Half-open intervals avoid showing a task ending at midnight twice.
      if (end <= range.start || start >= range.end) { outside.push(task); return; }
      const left = (Math.max(start, range.start) - range.start) / (range.end - range.start) * 100;
      const width = (Math.min(end, range.end) - Math.max(start, range.start)) / (range.end - range.start) * 100;
      visible.push({ task, left, width, continuesBefore: start < range.start, continuesAfter: end > range.end });
    });
    return { visible, outside };
  }
  function localDateKey(value) {
    const d = new Date(value);
    return Number.isFinite(+d) ? `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` : '';
  }
  function validDateKey(key) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(key)) return false;
    const [year, month, day] = key.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
  }
  function dailyAgenda(tasks, key) {
    if (!validDateKey(key)) return [];
    const [year, month, day] = key.split('-').map(Number);
    const start = +new Date(year, month - 1, day);
    const end = +new Date(year, month - 1, day + 1);
    return tasks.filter(task => {
      if (task.list === 'trash') return false;
      if (task.due === key) return true;
      const plannedStart = Date.parse(task.scheduledStart), plannedEnd = Date.parse(task.scheduledEnd);
      return Number.isFinite(plannedStart) && Number.isFinite(plannedEnd) && plannedEnd > plannedStart && plannedStart < end && plannedEnd > start;
    }).sort((a, b) => Number(!!a.done) - Number(!!b.done) || String(a.title || '').localeCompare(String(b.title || ''), 'es'));
  }
  function dailyMonth(tasks, key, today = Date.now()) {
    if (!validDateKey(key)) key = localDateKey(today);
    const [year, month] = key.split('-').map(Number);
    const first = new Date(year, month - 1, 1);
    const offset = (first.getDay() + 6) % 7;
    const start = new Date(year, month - 1, 1 - offset);
    const weeks = Math.ceil((offset + new Date(year, month, 0).getDate()) / 7);
    const days = Array.from({ length: weeks * 7 }, (_, i) => {
      const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
      const dateKey = localDateKey(date);
      const entries = dailyAgenda(tasks, dateKey);
      return { key: dateKey, day: date.getDate(), inMonth: date.getMonth() === month - 1, today: dateKey === localDateKey(today), count: entries.length, pending: entries.filter(task => !task.done).length };
    });
    return { days, month: first.toLocaleDateString('es-MX', { month: 'long', year: 'numeric' }), selected: key, agenda: dailyAgenda(tasks, key) };
  }
  function shiftDailyMonth(key, direction) {
    if (!validDateKey(key)) key = localDateKey(Date.now());
    const [year, month] = key.split('-').map(Number);
    return localDateKey(new Date(year, month - 1 + direction, 1));
  }
  function shiftDailyDay(key, direction) {
    if (!validDateKey(key)) key = localDateKey(Date.now());
    const [year, month, day] = key.split('-').map(Number);
    return localDateKey(new Date(year, month - 1, day + direction));
  }
  const api = { localInput, schedule, timeline, zone, calendar, shiftCalendar, calendarTasks, localDateKey, validDateKey, dailyAgenda, dailyMonth, shiftDailyMonth, shiftDailyDay };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.KaizenPlanning = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
