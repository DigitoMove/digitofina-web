const activateTab = (scope, target) => {
    const tabs = scope.querySelectorAll('[data-df-tab-target]');
    const panels = scope.querySelectorAll('[data-df-tab-panel]');

    tabs.forEach((tab) => {
        const isActive = tab.dataset.dfTabTarget === target;
        tab.classList.toggle('is-active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    panels.forEach((panel) => {
        panel.hidden = panel.dataset.dfTabPanel !== target;
    });
};

const initTabs = () => {
    document.querySelectorAll('[data-df-tabs]').forEach((scope) => {
        const firstTab = scope.querySelector('[data-df-tab-target]');
        const fromHash = window.location.hash ? scope.querySelector(`[data-df-tab-target="${window.location.hash.slice(1)}"]`) : null;
        const initial = fromHash?.dataset.dfTabTarget || firstTab?.dataset.dfTabTarget;

        if (!initial) {
            return;
        }

        activateTab(scope, initial);

        scope.querySelectorAll('[data-df-tab-target]').forEach((tab) => {
            tab.addEventListener('click', () => {
                activateTab(scope, tab.dataset.dfTabTarget);

                if (tab.dataset.dfTabHash !== 'false') {
                    history.replaceState(null, '', `#${tab.dataset.dfTabTarget}`);
                }
            });
        });
    });
};

const setStep = (scope, index) => {
    const steps = [...scope.querySelectorAll('[data-df-step]')];
    const panels = [...scope.querySelectorAll('[data-df-step-panel]')];
    const selected = Math.max(0, Math.min(index, panels.length - 1));

    panels.forEach((panel, panelIndex) => {
        panel.hidden = panelIndex !== selected;
    });

    steps.forEach((step, stepIndex) => {
        step.classList.toggle('is-active', stepIndex === selected);
        step.classList.toggle('is-complete', stepIndex < selected);
    });

    scope.dataset.dfCurrentStep = String(selected);
};

const initSteps = () => {
    document.querySelectorAll('[data-df-steps]').forEach((scope) => {
        setStep(scope, Number(scope.dataset.dfCurrentStep || 0));

        scope.querySelectorAll('[data-df-step]').forEach((step, index) => {
            step.addEventListener('click', () => setStep(scope, index));
        });

        scope.querySelectorAll('[data-df-step-next]').forEach((button) => {
            button.addEventListener('click', () => setStep(scope, Number(scope.dataset.dfCurrentStep || 0) + 1));
        });

        scope.querySelectorAll('[data-df-step-prev], [data-df-step-back]').forEach((button) => {
            button.addEventListener('click', () => setStep(scope, Number(scope.dataset.dfCurrentStep || 0) - 1));
        });
    });
};

const initRipples = () => {
    document.querySelectorAll('button, .df-action-primary, .df-action-secondary, .df-action-danger').forEach((element) => {
        element.classList.add('df-ripple');
        element.addEventListener('pointerdown', (event) => {
            const rect = element.getBoundingClientRect();
            element.style.setProperty('--df-ripple-x', `${event.clientX - rect.left}px`);
            element.style.setProperty('--df-ripple-y', `${event.clientY - rect.top}px`);
            element.classList.remove('is-rippling');
            void element.offsetWidth;
            element.classList.add('is-rippling');
        });
    });
};

const initKeyboardFocus = () => {
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Tab') {
            document.documentElement.classList.add('df-keyboard-user');
        }
    });
};

const initSidebar = () => {
    const sidebar = document.querySelector('[data-sidebar]');
    const openButton = document.querySelector('[data-sidebar-open]');
    const closeButtons = document.querySelectorAll('[data-sidebar-close]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const desktopQuery = window.matchMedia('(min-width: 1024px)');

    if (!sidebar || !openButton || !backdrop) {
        return;
    }

    const setOpen = (isOpen) => {
        sidebar.classList.toggle('-translate-x-full', !isOpen);
        sidebar.classList.toggle('translate-x-0', isOpen);
        backdrop.classList.toggle('hidden', !isOpen);
        document.body.classList.toggle('overflow-hidden', isOpen);
        openButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    };

    openButton.addEventListener('click', () => setOpen(true));
    closeButtons.forEach((button) => button.addEventListener('click', () => setOpen(false)));
    backdrop.addEventListener('click', () => setOpen(false));

    sidebar.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            if (!desktopQuery.matches) {
                setOpen(false);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });

    desktopQuery.addEventListener('change', (event) => {
        if (event.matches) {
            setOpen(false);
        }
    });
};

const formatMoney = (value) => {
    return new Intl.NumberFormat('en-UG', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value || 0));
};

const initLoanSchedulePreview = () => {
    document.querySelectorAll('[data-loan-schedule-preview]').forEach((form) => {
        const frequency = form.querySelector('[name="repayment_frequency"]');
        const product = form.querySelector('[name="loan_product_id"]');
        const packageInterestRate = form.querySelector('[data-package-interest-rate]');
        const unit = form.querySelector('[data-period-unit]');
        const button = form.querySelector('[data-preview-schedule]');
        const rows = form.querySelector('[data-preview-rows]');
        const error = form.querySelector('[data-preview-error]');

        const setUnit = () => {
            const labels = {
                daily: 'days',
                weekly: 'weeks',
                monthly: 'months',
            };

            if (unit && frequency) {
                unit.textContent = labels[frequency.value] || 'periods';
            }
        };

        const syncProductInterestRateDisplay = () => {
            const selectedRate = product?.selectedOptions?.[0]?.dataset?.interestRate;

            if (!packageInterestRate || !selectedRate) {
                return;
            }

            packageInterestRate.textContent = `${Number(selectedRate).toFixed(4)}%`;
        };

        const setError = (message) => {
            if (!error) {
                return;
            }

            error.textContent = message;
            error.hidden = !message;
        };

        const renderSummary = (summary) => {
            form.querySelector('[data-preview-installments]').textContent = summary.installments || 0;
            form.querySelector('[data-preview-principal]').textContent = formatMoney(summary.principal);
            form.querySelector('[data-preview-interest]').textContent = formatMoney(summary.interest);
            form.querySelector('[data-preview-total]').textContent = formatMoney(summary.total);
        };

        const renderRows = (schedule) => {
            if (!rows) {
                return;
            }

            if (!schedule.length) {
                rows.innerHTML = '<tr><td colspan="6" class="p-4 text-center text-slate-500">No preview yet.</td></tr>';

                return;
            }

            rows.innerHTML = schedule.map((row) => `
                <tr class="border-t border-slate-200">
                    <td class="p-2">${row.installment}</td>
                    <td>${row.due_date}</td>
                    <td>${formatMoney(row.principal)}</td>
                    <td>${formatMoney(row.interest)}</td>
                    <td>${formatMoney(row.total)}</td>
                    <td>${formatMoney(row.balance)}</td>
                </tr>
            `).join('');
        };

        const preview = async () => {
            const requestedAmount = form.querySelector('[name="requested_amount"]')?.value;
            const termPeriods = form.querySelector('[name="term_periods"]')?.value;

            if (!requestedAmount || !termPeriods || !product?.value || !frequency?.value) {
                return;
            }

            setError('');
            button.disabled = true;
            button.textContent = 'Previewing...';

            try {
                const response = await fetch(form.dataset.previewUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: new FormData(form),
                });
                const payload = await response.json();

                if (!response.ok) {
                    const message = payload.message || Object.values(payload.errors || {})?.[0]?.[0] || 'Unable to preview schedule.';
                    throw new Error(message);
                }

                renderSummary(payload.summary || {});
                renderRows(payload.schedule || []);
            } catch (previewError) {
                setError(previewError.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Preview Schedule';
            }
        };

        setUnit();
        syncProductInterestRateDisplay();
        frequency?.addEventListener('change', setUnit);
        frequency?.addEventListener('change', preview);
        product?.addEventListener('change', () => {
            syncProductInterestRateDisplay();
            preview();
        });
        form.querySelector('[name="requested_amount"]')?.addEventListener('change', preview);
        form.querySelector('[name="term_periods"]')?.addEventListener('change', preview);
        form.querySelector('[name="application_date"]')?.addEventListener('change', preview);
        button?.addEventListener('click', preview);
        preview();
    });
};

const initReportLauncher = () => {
    const modal = document.querySelector('[data-report-modal]');
    const form = document.querySelector('[data-report-form]');
    const title = document.querySelector('[data-report-modal-title]');
    const dateFields = document.querySelector('[data-report-date-fields]');
    const detailField = document.querySelector('[data-report-detail-field]');

    if (!modal || !form) {
        return;
    }

    const close = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    document.querySelectorAll('[data-report-open]').forEach((button) => {
        button.addEventListener('click', () => {
            form.action = button.dataset.reportUrl || '';

            if (title) {
                title.textContent = button.dataset.reportTitle || 'Generate Report';
            }

            if (dateFields) {
                dateFields.hidden = button.dataset.reportDates !== '1';
                dateFields.querySelectorAll('input').forEach((input) => {
                    input.disabled = dateFields.hidden;
                });
            }

            if (detailField) {
                detailField.hidden = button.dataset.reportDetail !== '1';
                detailField.querySelectorAll('select').forEach((select) => {
                    select.disabled = detailField.hidden;
                });
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        });
    });

    modal.querySelectorAll('[data-report-close]').forEach((button) => {
        button.addEventListener('click', close);
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
        }
    });
};

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initSteps();
    initRipples();
    initKeyboardFocus();
    initSidebar();
    initLoanSchedulePreview();
    initReportLauncher();
});
