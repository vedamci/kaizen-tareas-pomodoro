(function (root) {
  'use strict';
  // Assistant runs are a separate entity from Kaizen tasks. Nothing here reads
  // done, owners, sharing, _delegated_at, scope, Pomodoros, seconds or _revision.
  const STATUSES = ['queued', 'in_progress', 'blocked', 'completed', 'cancelled'];
  const OPEN = ['queued', 'in_progress', 'blocked'];
  const STATUS_LABELS = { queued: 'En cola', in_progress: 'En curso', blocked: 'Bloqueada', completed: 'Completada', cancelled: 'Cancelada', unknown: 'Estado sin dato' };
  const EVENT_LABELS = {
    delegated: 'Delegación registrada', status_verified: 'Estado verificado', status_changed: 'Cambio de estado', progress_note: 'Avance',
    blocker_set: 'Bloqueo registrado', blocker_cleared: 'Bloqueo resuelto', next_step_set: 'Próximo paso',
    next_step_cleared: 'Próximo paso actualizado', deliverable_added: 'Entregable añadido', deliverable_removed: 'Entregable actualizado', work_started: 'Inicio de trabajo', work_paused: 'Pausa de trabajo',
    completed: 'Completada', cancelled: 'Cancelada'
  };
  const MISSING = 'Sin dato';
  const text = value => typeof value === 'string' ? value.trim() : '';

  // Only explicit ISO 8601 instants with a UTC designator or offset are valid.
  // Numbers, local-only strings and impossible dates are rejected, not guessed.
  const ISO = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,3})?)?(Z|[+-]\d{2}:\d{2})$/;
  function parseTime(value) {
    if (typeof value !== 'string') return null;
    const match = ISO.exec(value.trim());
    if (!match) return null;
    const [, y, mo, d, h, mi, s = '0'] = match.map(Number);
    if (mo < 1 || mo > 12 || d < 1 || d > new Date(Date.UTC(y, mo, 0)).getUTCDate() || h > 23 || mi > 59 || s > 59) return null;
    const time = Date.parse(value.trim());
    return Number.isFinite(time) ? time : null;
  }

  // An assistant run exists only through an explicit record of delegation to
  // the assistant: its own kind, an assistant recipient and a delegated event.
  function isAssistantRun(record) {
    return !!record && typeof record === 'object' && record.kind === 'assistant_run' &&
      !!text(record.id) && !!text(record.assistant) && Array.isArray(record.events) &&
      record.events.some(event => event && event.type === 'delegated');
  }

  function validEvents(run) {
    const seen = new Set();
    return (Array.isArray(run.events) ? run.events : []).filter(event => {
      if (!event || typeof event !== 'object' || !text(event.id) || !EVENT_LABELS[event.type] || seen.has(event.id)) return false;
      seen.add(event.id);
      return true;
    });
  }

  // Pair explicit work_started/work_paused events in recorded order. Open,
  // reversed, duplicated or unmatched records never add time.
  function activeTime(events) {
    const result = { ms: 0, intervals: 0, open: false, ignored: 0 };
    const seen = new Set();
    let start = null;
    for (const event of Array.isArray(events) ? events : []) {
      if (!event || (event.type !== 'work_started' && event.type !== 'work_paused')) continue;
      const time = parseTime(event.at);
      const key = event.type + '|' + event.at;
      if (time === null || seen.has(key) || (event.id && seen.has('id|' + event.id))) { result.ignored++; continue; }
      seen.add(key); if (event.id) seen.add('id|' + event.id);
      if (event.type === 'work_started') {
        if (start !== null) result.ignored++; // previous start never closed
        start = time;
      } else if (start === null) {
        result.ignored++; // pause without a start
      } else {
        if (time > start) { result.ms += time - start; result.intervals++; } else result.ignored += 2;
        start = null;
      }
    }
    if (start !== null) { result.open = true; result.ignored++; }
    return result;
  }

  function summarize(run, now = Date.now()) {
    const status = STATUSES.includes(run.status) ? run.status : 'unknown';
    const events = validEvents(run);
    const delegatedAt = parseTime(run.delegated_at);
    const closingType = status === 'completed' ? 'completed' : status === 'cancelled' ? 'cancelled' : '';
    // A closing time is used only with its status and its recorded event.
    const closedAt = closingType && events.some(e => e.type === closingType) ? parseTime(closingType === 'completed' ? run.completed_at : run.closed_at) : null;
    let elapsed = { kind: OPEN.includes(status) ? 'open' : status === 'completed' ? 'resolution' : status === 'cancelled' ? 'cancelled' : 'unknown', ms: null };
    if (delegatedAt !== null) {
      if (elapsed.kind === 'open' && now >= delegatedAt) elapsed.ms = now - delegatedAt;
      if ((elapsed.kind === 'resolution' || elapsed.kind === 'cancelled') && closedAt !== null && closedAt >= delegatedAt) elapsed.ms = closedAt - delegatedAt;
    }
    const timed = events.map((event, index) => ({ event, index, time: parseTime(event.at) }));
    const latest = type => timed.filter(e => e.time !== null && (!type || type.includes(e.event.type))).sort((a, b) => b.time - a.time || b.index - a.index)[0] || null;
    const lastEvent = latest();
    const progress = latest(['progress_note']);
    const blockerEvent = latest(['blocker_set', 'blocker_cleared']);
    const nextEvent = latest(['next_step_set', 'next_step_cleared']);
    const latestDeliverables = new Map();
    timed.filter(e => (e.event.type === 'deliverable_added' || e.event.type === 'deliverable_removed') && text(e.event.summary))
      .sort((a, b) => (a.time ?? -Infinity) - (b.time ?? -Infinity) || a.index - b.index)
      .forEach(e => latestDeliverables.set(text(e.event.summary), e));
    const deliverables = [...latestDeliverables.values()].filter(e => e.event.type === 'deliverable_added')
      .sort((a, b) => (b.time ?? -Infinity) - (a.time ?? -Infinity) || b.index - a.index)
      .map(e => ({ id: e.event.id, label: text(e.event.summary), at: e.time }));
    // Reverse chronological; events whose time is not verifiable go last.
    const timeline = timed.slice().sort((a, b) => (a.time === null) - (b.time === null) || (b.time ?? 0) - (a.time ?? 0) || b.index - a.index)
      .map(e => ({ id: e.event.id, type: e.event.type, label: EVENT_LABELS[e.event.type], summary: text(e.event.summary), actor: text(e.event.actor), at: e.time }));
    return {
      id: run.id, title: text(run.title) || MISSING, assistant: text(run.assistant), brief: text(run.brief),
      status, statusLabel: STATUS_LABELS[status], open: OPEN.includes(status), resolved: status === 'completed' && elapsed.ms !== null,
      delegatedAt, closedAt, elapsed,
      lastActivityAt: lastEvent ? lastEvent.time : null,
      progress: progress && text(progress.event.summary) ? { summary: text(progress.event.summary), at: progress.time } : null,
      blocker: OPEN.includes(status) && blockerEvent && blockerEvent.event.type === 'blocker_set' && text(blockerEvent.event.summary) ? { summary: text(blockerEvent.event.summary), at: blockerEvent.time } : null,
      nextStep: OPEN.includes(status) && nextEvent && nextEvent.event.type === 'next_step_set' && text(nextEvent.event.summary) ? { summary: text(nextEvent.event.summary), at: nextEvent.time } : null,
      deliverables, active: activeTime(events), timeline
    };
  }

  function summarizeAll(records, now = Date.now()) {
    return (Array.isArray(records) ? records : []).filter(isAssistantRun).map(run => summarize(run, now));
  }

  const normalize = value => String(value || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  function matches(summary, query) {
    const q = normalize(query).trim();
    if (!q) return true;
    return [summary.title, summary.brief, summary.progress?.summary, summary.blocker?.summary, summary.nextStep?.summary, ...summary.deliverables.map(d => d.label)]
      .some(value => normalize(value).includes(q));
  }
  // Filtering is read-only: it returns new arrays and never mutates a run.
  function filter(summaries, { status = 'all', query = '' } = {}) {
    const searched = summaries.filter(s => matches(s, query));
    const counts = { all: searched.length, unknown: 0 };
    STATUSES.forEach(key => { counts[key] = 0; });
    searched.forEach(s => { counts[s.status] = (counts[s.status] || 0) + 1; });
    const list = status === 'all' ? searched : searched.filter(s => s.status === status);
    const order = s => s.lastActivityAt ?? s.delegatedAt ?? -Infinity;
    return { list: list.slice().sort((a, b) => Number(b.open) - Number(a.open) || order(b) - order(a) || a.title.localeCompare(b.title, 'es')), counts };
  }

  function duration(ms) {
    if (!Number.isFinite(ms) || ms < 0) return MISSING;
    const minutes = Math.floor(ms / 60000);
    if (minutes < 1) return 'menos de 1 min';
    const days = Math.floor(minutes / 1440), hours = Math.floor(minutes % 1440 / 60), mins = minutes % 60;
    if (days) return days + ' d' + (hours ? ' ' + hours + ' h' : '');
    if (hours) return hours + ' h' + (mins ? ' ' + mins + ' min' : '');
    return mins + ' min';
  }
  function relative(time, now = Date.now()) {
    if (!Number.isFinite(time)) return MISSING;
    return now >= time ? 'hace ' + duration(now - time) : 'en ' + duration(time - now);
  }
  function absolute(time) {
    if (!Number.isFinite(time)) return MISSING;
    return new Date(time).toLocaleString('es-MX', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZoneName: 'short' });
  }
  function iso(time) { return Number.isFinite(time) ? new Date(time).toISOString() : ''; }

  const api = { STATUSES, OPEN, STATUS_LABELS, EVENT_LABELS, MISSING, parseTime, isAssistantRun, activeTime, summarize, summarizeAll, filter, duration, relative, absolute, iso };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.KaizenAssistantRuns = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
