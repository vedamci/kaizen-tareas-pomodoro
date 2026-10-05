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
  const api = { localInput, schedule, timeline, zone };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.KaizenPlanning = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
