/* Durable AI jobs: HTTP timeouts never cancel work or restart an accepted batch. */
(function (global) {
    'use strict';
    const base = typeof BASE_URL !== 'undefined' ? BASE_URL : '/';
    const endpoint = new URL('admin/ajax/ai-jobs.php', new URL(base, location.href)).href;
    const jobsUrl = new URL('admin/ai/jobs.php', new URL(base, location.href)).href;
    const terminal = new Set(['succeeded', 'failed', 'cancelled']);
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    function requestKey() {
        const bytes = new Uint8Array(24);
        global.crypto.getRandomValues(bytes);
        return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    }
    function recoveryUrl(batch) {
        const url = new URL(jobsUrl);
        if (batch) url.searchParams.set('batch_id', batch);
        return url.href;
    }
    function notice(message, batch, warning, active) {
        let box = document.getElementById('aiJobsNotice');
        if (!box) {
            box = document.createElement('div');
            box.id = 'aiJobsNotice';
            box.setAttribute('role', 'status');
            (document.querySelector('main') || document.querySelector('.container-fluid') || document.body).prepend(box);
        }
        box.className = 'alert ' + (warning ? 'alert-warning' : 'alert-info');
        if (active) {
            const spin = document.createElement('span');
            spin.className = 'spinner-border spinner-border-sm me-2 align-text-bottom';
            box.replaceChildren(spin, document.createTextNode(message + ' '));
        } else {
            box.replaceChildren(document.createTextNode(message + ' '));
        }
        const link = document.createElement('a');
        link.href = recoveryUrl(batch);
        link.className = 'alert-link';
        link.textContent = 'Mở hàng đợi AI / khôi phục kết quả';
        box.append(link);
    }
    async function http(url, options) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(url, Object.assign({credentials: 'same-origin', cache: 'no-store'}, options, {signal: controller.signal}));
            let data;
            try { data = await response.json(); } catch (_) {
                const error = new Error('Phản hồi không hợp lệ. Kiểm tra đăng nhập và hàng đợi AI.');
                error.transient = response.status >= 500 || response.ok;
                throw error;
            }
            if (!response.ok || !data.success) {
                const error = new Error(data.message || data.error || ('HTTP ' + response.status));
                error.transient = response.status === 429 || response.status >= 500;
                throw error;
            }
            return data;
        } catch (error) {
            if (error.name === 'AbortError' || error instanceof TypeError) {
                error.transient = true;
                error.message = 'Mất kết nối hoặc hết thời gian HTTP; tác vụ đã nhận vẫn tiếp tục chạy.';
            }
            throw error;
        } finally { clearTimeout(timer); }
    }
    async function send(url, body, options = {}) {
        // Keep a caller-supplied key; reuse the same payload for every transport retry.
        const payload = body instanceof FormData ? new FormData() : new URLSearchParams(body);
        if (body instanceof FormData) body.forEach((value, key) => payload.append(key, value));
        if (!payload.get('csrf_token')) payload.set('csrf_token', csrf());
        if (!payload.get('request_key')) payload.set('request_key', options.requestKey || requestKey());
        notice('Đang gửi tác vụ. Có thể rời trang sau khi tác vụ được nhận.', null, false);
        for (let attempt = 0; ; attempt++) {
            try {
                const data = await http(url, {method: 'POST', body: payload});
                if (!data.queued || !data.batch_id || !Array.isArray(data.job_ids) || !data.job_ids.length) {
                    throw new Error('Máy chủ chưa trả về lô tác vụ hợp lệ. Kiểm tra hàng đợi trước khi gửi lại.');
                }
                notice('Đã xếp hàng ' + data.job_ids.length + ' tác vụ. Đóng trang không hủy tác vụ.', data.batch_id, false, true);
                return data;
            } catch (error) {
                if (error.transient && attempt < 2) { await sleep(1000 * (attempt + 1)); continue; }
                error.requestKey = payload.get('request_key');
                error.recoveryUrl = recoveryUrl();
                notice(error.message + ' Không gửi một yêu cầu mới nếu chưa kiểm tra hàng đợi.', null, true);
                if (error.transient) {
                    const retry = document.createElement('button');
                    retry.type = 'button';
                    retry.className = 'btn btn-sm btn-outline-secondary ms-2';
                    retry.textContent = 'Gửi lại cùng mã yêu cầu';
                    retry.addEventListener('click', async () => {
                        retry.disabled = true;
                        try { const accepted = await send(url, payload); location.href = recoveryUrl(accepted.batch_id); }
                        catch (_) { /* send renders recovery controls; never invent a new key. */ }
                    });
                    document.getElementById('aiJobsNotice').append(retry);
                }
                throw error;
            }
        }
    }
    function list(params = {}) {
        const url = new URL(endpoint);
        Object.entries(params).forEach(([key, value]) => { if (value !== '' && value != null) url.searchParams.set(key, value); });
        return http(url.href, {method: 'GET'});
    }
    function action(actionName, id) {
        if (!['retry', 'cancel'].includes(actionName)) return Promise.reject(new Error('Hành động không hợp lệ.'));
        // Deliberately do not automatically retry mutations after an uncertain response.
        return http(endpoint, {method: 'POST', body: new URLSearchParams({action: actionName, id: String(id), csrf_token: csrf()})});
    }
    function workerWarning(worker) {
        if (!worker || !worker.last_seen) return 'Chưa có heartbeat worker. Kiểm tra CLI/Cron; tác vụ vẫn nằm trong hàng đợi.';
        const seen = Number(worker.last_seen_unix);
        const serverNow = Number(worker.server_now_unix);
        const age = Number.isFinite(seen) && Number.isFinite(serverNow) ? Math.max(0, serverNow - seen) * 1000 : Infinity;
        if (age > 360000 || ['stopped', 'error', 'offline'].includes(worker.state)) {
            return 'Worker chưa hoạt động gần đây. Kiểm tra CLI/Cron; không cần gửi lại tác vụ.';
        }
        return '';
    }
    function progressText(progress) {
        if (progress.error) return 'Đang thử đọc lại trạng thái; tác vụ vẫn chạy. ' + progress.error;
        const active = progress.jobs.find(job => !terminal.has(job.status));
        return 'Hoàn tất ' + progress.done + '/' + progress.total + ' — ' + progress.succeeded + ' thành công, ' + progress.failed + ' lỗi, ' + progress.cancelled + ' đã hủy' + (active ? ' — ' + active.status + (active.stage ? ': ' + active.stage : '') : '') + (progress.warning ? '. ' + progress.warning : '');
    }
    async function waitBatch(batch, options = {}) {
        const accepted = typeof batch === 'string' ? {batch_id: batch, job_ids: []} : batch;
        const expected = new Set((accepted.job_ids || []).map(Number));
        const known = new Map();
        let failures = 0;
        while (true) {
            try {
                let page = 1, data;
                do {
                    data = await list({batch_id: accepted.batch_id, page});
                    (data.jobs || []).forEach(job => {
                        if (!expected.size || expected.has(Number(job.id))) known.set(Number(job.id), job);
                    });
                    page++;
                } while (data.has_more);
                failures = 0;
                const jobs = Array.from(known.values());
                const progress = {
                    batch_id: accepted.batch_id, jobs, worker: data.worker,
                    total: expected.size || jobs.length,
                    done: jobs.filter(job => terminal.has(job.status)).length,
                    succeeded: jobs.filter(job => job.status === 'succeeded').length,
                    failed: jobs.filter(job => job.status === 'failed').length,
                    cancelled: jobs.filter(job => job.status === 'cancelled').length,
                    warning: workerWarning(data.worker)
                };
                notice(progressText(progress), accepted.batch_id, !!progress.warning || !!progress.failed, progress.done < progress.total);
                if (options.onProgress) options.onProgress(progress);
                if (progress.total > 0 && progress.done === progress.total) return jobs;
            } catch (error) {
                failures++;
                notice(error.message + ' Theo dõi lại ở hàng đợi; không gửi lại tác vụ.', accepted.batch_id, true);
                if (options.onProgress) options.onProgress({error: error.message, batch_id: accepted.batch_id, jobs: Array.from(known.values())});
                if (!error.transient) { error.recoveryUrl = recoveryUrl(accepted.batch_id); throw error; }
                // Keep polling accepted jobs. A status outage must not make the UI conclude that server-side work stopped.
            }
            await sleep(Math.min(15000, 2500 * Math.max(1, failures)));
        }
    }
    function jobResult(job) {
        if (job.status === 'succeeded') return job.result || {success: true};
        const partial = job.result && typeof job.result === 'object' ? job.result : {};
        const message = (job.message || 'Tác vụ ' + job.status) + (job.result ? ' Có kết quả một phần/nội dung có thể đã lưu. Kiểm tra chi tiết trước khi thử lại.' : '');
        notice(message, job.batch_id, true);
        return Object.assign({}, partial, {success: false, message});
    }
    async function run(url, body, options = {}) {
        const accepted = await send(url, body, options);
        const jobs = await waitBatch(accepted, options);
        if (jobs.length !== 1) throw new Error('Dùng runImport hoặc waitBatch cho nhiều tác vụ.');
        return jobResult(jobs[0]);
    }
    async function runImport(url, body, options = {}) {
        const accepted = await send(url, body, options);
        const jobs = await waitBatch(accepted, options);
        const results = jobs.map(jobResult);
        const created = results.reduce((sum, result) => sum + Number(result.created || 0), 0);
        const failed = jobs.filter(job => job.status !== 'succeeded').length;
        return {success: failed === 0, created, skipped: results.reduce((sum, result) => sum + Number(result.skipped || 0), 0), items: results.flatMap(result => result.items || []), failed, jobs, batch_id: accepted.batch_id, message: 'Đã tạo ' + created + ' bài nháp; ' + failed + ' tác vụ lỗi/đã hủy. Kiểm tra hàng đợi để xem kết quả một phần.'};
    }
    global.AIJobs = {send, list, action, run, runImport, waitBatch, requestKey, recoveryUrl, notice, workerWarning, progressText};
})(window);
