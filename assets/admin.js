(function () {
	'use strict';

	function $(id) {
		return document.getElementById(id);
	}

	function setBannerHTML(html) {
		var box = $('feedico-last-sync-banner');
		if (box && html) {
			box.outerHTML = html;
		}
	}

	function setLastSyncDurationFromAjax(data) {
		var dEl = $('feedico-last-sync-duration');
		if (!dEl || !data || !data.data) {
			return;
		}
		if (!Object.prototype.hasOwnProperty.call(data.data, 'last_sync_duration')) {
			return;
		}
		var t = data.data.last_sync_duration;
		dEl.textContent = t ? t : '\u2014';
	}

	var testBtn = $('feedico-test-connection');
	if (testBtn) {
		testBtn.addEventListener('click', function () {
			var status = $('feedico-test-status');
			if (status) {
				status.textContent = feedicoSync.strings.testing;
			}
			var body = new URLSearchParams({
				action: 'feedico_sync_test',
				nonce: feedicoSync.nonce,
				email: $('feedico_email').value,
				password: $('feedico_password').value,
				token: $('feedico_token').value
			});
			fetch(feedicoSync.ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body
			})
				.then(function (r) {
					return r.json();
				})
				.then(function (data) {
					if (data.success) {
						if (status) {
							status.textContent = feedicoSync.strings.testOk;
							status.className = 'feedico-inline-status feedico-ok';
						}
						window.location.reload();
					} else {
						if (status) {
							status.textContent =
								data.data && data.data.message ? data.data.message : 'Error';
							status.className = 'feedico-inline-status feedico-err';
						}
					}
				})
				.catch(function () {
					if (status) {
						status.textContent = feedicoSync.strings.requestFailed;
						status.className = 'feedico-inline-status feedico-err';
					}
				});
		});
	}

	var runBtn = $('feedico-run-sync');
	if (runBtn) {
		runBtn.addEventListener('click', function () {
			var el = $('feedico-sync-run-status');
			runBtn.disabled = true;
			if (el) {
				el.textContent = feedicoSync.strings.syncRunning;
				el.className = 'feedico-run-status feedico-run-running';
			}
			var body = new URLSearchParams({
				action: 'feedico_sync_run',
				nonce: feedicoSync.nonce
			});
			fetch(feedicoSync.ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body
			})
				.then(function (r) {
					return r.json();
				})
				.then(function (data) {
					runBtn.disabled = false;
					if (!el) {
						return;
					}
					if (data.success) {
						el.textContent = data.data.message || feedicoSync.strings.syncOk;
						el.className = 'feedico-run-status feedico-run-ok';
						if (data.data.banner_html) {
							setBannerHTML(data.data.banner_html);
						}
						setLastSyncDurationFromAjax(data);
					} else {
						el.textContent =
							data.data && data.data.message
								? data.data.message
								: feedicoSync.strings.syncFail;
						el.className = 'feedico-run-status feedico-run-err';
						if (data.data && data.data.banner_html) {
							setBannerHTML(data.data.banner_html);
						}
						setLastSyncDurationFromAjax(data);
					}
				})
				.catch(function () {
					runBtn.disabled = false;
					if (el) {
						el.textContent = feedicoSync.strings.requestFailed;
						el.className = 'feedico-run-status feedico-run-err';
					}
				});
		});
	}

	/* Show/hide custom minutes when "Custom interval" is selected */
	var schedSel = $('feedico_cron_interval');
	var customWrap = $('feedico-custom-interval-wrap');
	function syncScheduleCustomVisibility() {
		if (!schedSel || !customWrap) {
			return;
		}
		if (schedSel.value === 'feedico_custom') {
			customWrap.classList.add('feedico-custom-interval-wrap--open');
		} else {
			customWrap.classList.remove('feedico-custom-interval-wrap--open');
		}
	}
	if (schedSel && customWrap) {
		schedSel.addEventListener('change', syncScheduleCustomVisibility);
		syncScheduleCustomVisibility();
	}

	/* Fresh dashboard from API when the settings screen loads (saved credentials only). */
	function getCheckedNetworkIds() {
		var fs = document.querySelector('.feedico-network-fieldset');
		var ids = {};
		if (fs) {
			fs.querySelectorAll('input[type="checkbox"][name="feedico_networks[]"]').forEach(function (cb) {
				if (cb.checked) {
					ids[cb.value] = true;
				}
			});
		}
		return ids;
	}

	function escapeHtml(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	function escapeAttr(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/</g, '&lt;');
	}

	function applyNetworksCatalog(catalog) {
		var body = $('feedico-networks-body');
		if (!body || !feedicoSync.strings) {
			return;
		}
		var checked = getCheckedNetworkIds();
		if (!catalog || !catalog.length) {
			body.innerHTML =
				'<p class="description feedico-networks-empty">' +
				escapeHtml(feedicoSync.strings.networksEmpty || '') +
				'</p>';
			return;
		}
		var hint =
			'<p class="feedico-card-lead feedico-networks-hint">' +
			escapeHtml(feedicoSync.strings.networksHint || '') +
			'</p>';
		var html = hint + '<fieldset class="feedico-network-fieldset">';
		catalog.forEach(function (row) {
			if (!row || row.id === undefined || row.id === null || String(row.id) === '') {
				return;
			}
			var id = String(row.id);
			var label = row.label != null ? String(row.label) : id;
			var prov = row.provider != null ? String(row.provider) : '';
			var isOn = checked[id] ? ' checked' : '';
			html +=
				'<label class="feedico-network-label"><input type="checkbox" name="feedico_networks[]" value="' +
				escapeAttr(id) +
				'"' +
				isOn +
				' /><span class="feedico-network-label-text">' +
				escapeHtml(label) +
				'</span>';
			if (prov) {
				html +=
					'<span class="feedico-network-label-prov">' + escapeHtml(prov) + '</span>';
			}
			html += '</label>';
		});
		html += '</fieldset>';
		body.innerHTML = html;
	}

	function refreshDashboardFromServer() {
		var dashWrap = $('feedico-dashboard-cards-wrap');
		if (!dashWrap || typeof feedicoSync === 'undefined') {
			return;
		}
		dashWrap.classList.add('feedico-dashboard-refreshing');
		var body = new URLSearchParams({
			action: 'feedico_sync_refresh_dashboard',
			nonce: feedicoSync.nonce
		});
		fetch(feedicoSync.ajaxurl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (data) {
				dashWrap.classList.remove('feedico-dashboard-refreshing');
				if (data.success && data.data && data.data.dashboard_html) {
					dashWrap.innerHTML = data.data.dashboard_html;
				}
				if (data.success && data.data && data.data.catalog !== undefined) {
					applyNetworksCatalog(data.data.catalog);
				}
			})
			.catch(function () {
				dashWrap.classList.remove('feedico-dashboard-refreshing');
			});
	}
	refreshDashboardFromServer();
})();
