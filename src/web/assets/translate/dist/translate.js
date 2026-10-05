/**
 * Supertext box on the entry edit page: lists the entry's other sites, translates the
 * current site's saved version into the chosen ones and links to the results.
 */
(function () {
    const t = (message, params) => Craft.t('supertext-translation', message, params);
    const esc = (value) => Craft.escapeHtml(String(value));

    function siteUrl(handle) {
        const url = new URL(window.location.href);
        url.searchParams.set('site', handle);
        url.searchParams.delete('draftId');
        return url.toString();
    }

    async function init(box) {
        const body = box.querySelector('.supertext-body');
        const entryId = box.dataset.entryId;
        const siteId = box.dataset.siteId;
        let info;
        try {
            info = (await Craft.sendActionRequest('GET', 'supertext-translation/translate/info', { params: { entryId, siteId } })).data;
        } catch (e) {
            body.innerHTML = `<p class="error">${esc(e?.response?.data?.message ?? e)}</p>`;
            return;
        }
        if (!info.configured) {
            body.innerHTML = `<p class="warning">${esc(t('Supertext is not set up yet: an administrator needs to add the API key in the plugin settings.'))}</p>`;
            return;
        }
        const source = info.sites.find((s) => s.isSource);
        const targets = info.sites.filter((s) => !s.isSource && s.canSave);
        if (!targets.length) {
            body.innerHTML = `<p class="light">${esc(t('This entry exists in no other site you can edit.'))}</p>`;
            return;
        }

        const date = (iso) => Craft.formatDate ? Craft.formatDate(new Date(iso)) : new Date(iso).toLocaleDateString();
        body.innerHTML = `
            <p class="st-label">${esc(t('From'))}</p>
            <p class="st-source">${esc(source.name)} <code>${esc(source.language)}</code></p>
            <p class="st-label">${esc(t('Into'))}</p>
            ${targets.map((s) => `
                <div class="st-site">
                    <input type="checkbox" class="checkbox" id="st-site-${s.id}" value="${s.id}" ${s.translated ? '' : 'checked'}>
                    <label for="st-site-${s.id}">${esc(s.name)} <code>${esc(s.language)}</code></label>
                    ${s.lastTranslation
                        ? `<span class="st-chip">${esc(t('Translated with Supertext on {date}', { date: date(s.lastTranslation) }))}</span>`
                        : s.translated ? `<span class="st-chip">${esc(t('Already translated'))}</span>` : ''}
                </div>`).join('')}
            <div class="st-warning hidden">
                <input type="checkbox" class="checkbox" id="st-overwrite">
                <label for="st-overwrite"><strong>${esc(t('Overwrite existing translations'))}</strong></label>
                <p>${esc(t('Changes made to those translations are replaced by a new translation. Leave this off to translate only the sites without their own text.'))}</p>
            </div>
            <p class="st-hint">${esc(t('Supertext translates the saved version of this site. Save your changes first.'))}</p>
            <button type="button" class="btn submit st-translate">${esc(t('Translate'))}</button>
            <ul class="st-results" aria-live="polite"></ul>`;

        const boxes = [...body.querySelectorAll('.st-site input')];
        const warning = body.querySelector('.st-warning');
        const overwrite = body.querySelector('#st-overwrite');
        const button = body.querySelector('.st-translate');
        const results = body.querySelector('.st-results');

        const update = () => {
            const chosen = boxes.filter((b) => b.checked).map((b) => Number(b.value));
            const replacing = targets.some((s) => s.translated && chosen.includes(s.id));
            warning.classList.toggle('hidden', !replacing);
            button.disabled = chosen.length === 0;
            button.classList.toggle('disabled', chosen.length === 0);
        };
        boxes.forEach((b) => b.addEventListener('change', update));
        update();

        button.addEventListener('click', async () => {
            const targetSiteIds = boxes.filter((b) => b.checked).map((b) => Number(b.value));
            if (!targetSiteIds.length) {
                Craft.cp.displayError(t('Choose at least one site.'));
                return;
            }
            button.classList.add('loading');
            button.disabled = true;
            button.textContent = t('Translating…');
            results.innerHTML = '';
            try {
                const response = await Craft.sendActionRequest('POST', 'supertext-translation/translate/entry', {
                    data: { entryId, sourceSiteId: source.id, targetSiteIds, overwrite: overwrite.checked },
                });
                results.innerHTML = response.data.results.map((r) => {
                    const site = info.sites.find((s) => s.id === r.siteId);
                    if (r.status === 'translated') {
                        return `<li class="ok">${esc(r.site)}: ${esc(t('translated'))} – <a href="${esc(siteUrl(site.handle))}">${esc(t('Open'))}</a></li>`;
                    }
                    if (r.status === 'skipped') {
                        return `<li class="skipped">${esc(r.site)}: ${esc(t('already translated, skipped'))}</li>`;
                    }
                    return `<li class="error">${esc(r.site)}: ${esc(r.message ?? '')}</li>`;
                }).join('');
            } catch (e) {
                results.innerHTML = `<li class="error">${esc(e?.response?.data?.message ?? e)}</li>`;
            } finally {
                button.classList.remove('loading');
                button.textContent = t('Translate');
                update();
            }
        });
    }

    const start = () => document.querySelectorAll('[data-supertext]:not([data-supertext-ready])').forEach((box) => {
        box.setAttribute('data-supertext-ready', '');
        init(box);
    });
    start();
    // The edit page can re-render its sidebar (e.g. after switching sites in a slideout).
    new MutationObserver(start).observe(document.body, { childList: true, subtree: true });
})();
