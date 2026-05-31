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
    const openButtons = document.querySelectorAll('[data-sidebar-open]');
    const closeButtons = document.querySelectorAll('[data-sidebar-close]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const desktopQuery = window.matchMedia('(min-width: 1024px)');

    if (!sidebar || !openButtons.length || !backdrop) {
        return;
    }

    const setOpen = (isOpen) => {
        sidebar.classList.toggle('-translate-x-full', !isOpen);
        sidebar.classList.toggle('translate-x-0', isOpen);
        backdrop.classList.toggle('hidden', !isOpen);
        document.body.classList.toggle('overflow-hidden', isOpen);
        openButtons.forEach((openButton) => openButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false'));
    };

    openButtons.forEach((openButton) => openButton.addEventListener('click', () => setOpen(true)));
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

const initConfirmDialog = () => {
    const dialog = document.querySelector('[data-confirm-dialog]');
    const title = dialog?.querySelector('[data-confirm-title]');
    const message = dialog?.querySelector('[data-confirm-message]');
    const summary = dialog?.querySelector('[data-confirm-summary]');
    const acceptButton = dialog?.querySelector('[data-confirm-accept]');
    const cancelButton = dialog?.querySelector('[data-confirm-cancel]');
    const icon = dialog?.querySelector('[data-confirm-icon]');
    let pendingForm = null;

    if (!dialog || !title || !message || !summary || !acceptButton || !cancelButton || !icon) {
        return;
    }

    const toneClasses = {
        danger: ['bg-red-50', 'text-red-700'],
        warning: ['bg-amber-50', 'text-amber-700'],
        neutral: ['bg-slate-100', 'text-slate-700'],
    };
    const buttonClasses = {
        danger: ['bg-red-700', 'hover:bg-red-800', 'focus:ring-red-500'],
        warning: ['bg-amber-600', 'hover:bg-amber-700', 'focus:ring-amber-500'],
        neutral: ['bg-slate-900', 'hover:bg-brand-800', 'focus:ring-brand-500'],
    };
    const allIconClasses = Object.values(toneClasses).flat();
    const allButtonClasses = Object.values(buttonClasses).flat();
    const escapeHtml = (value) => String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const close = () => {
        dialog.classList.add('hidden');
        dialog.classList.remove('flex');
        dialog.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden');
        pendingForm = null;
    };

    const open = (form) => {
        const tone = form.dataset.confirmTone || 'warning';
        pendingForm = form;
        title.textContent = form.dataset.confirmTitle || 'Confirm action';
        message.textContent = form.dataset.confirmMessage || 'Please confirm that you want to continue.';
        acceptButton.textContent = form.dataset.confirmLabel || 'Confirm';
        const summaryRows = [...form.querySelectorAll('[data-confirm-summary-label]')]
            .map((field) => {
                const label = field.dataset.confirmSummaryLabel;
                const value = field.dataset.confirmSummaryValue
                    || (field.tagName === 'SELECT' ? field.selectedOptions?.[0]?.textContent : field.value)
                    || field.textContent
                    || '';

                return [label, value.trim()];
            })
            .filter(([label, value]) => label && value);

        summary.innerHTML = summaryRows.map(([label, value]) => `
            <div class="grid gap-1 px-3 py-2 sm:grid-cols-[8rem_1fr] sm:gap-3">
                <dt class="font-semibold text-slate-500">${escapeHtml(label)}</dt>
                <dd class="break-words font-medium text-slate-950">${escapeHtml(value)}</dd>
            </div>
        `).join('');
        summary.classList.toggle('hidden', summaryRows.length === 0);
        icon.classList.remove(...allIconClasses);
        icon.classList.add(...(toneClasses[tone] || toneClasses.warning));
        acceptButton.classList.remove(...allButtonClasses);
        acceptButton.classList.add(...(buttonClasses[tone] || buttonClasses.warning));
        dialog.classList.remove('hidden');
        dialog.classList.add('flex');
        dialog.setAttribute('aria-hidden', 'false');
        document.body.classList.add('overflow-hidden');
        acceptButton.focus();
    };

    document.querySelectorAll('form[data-confirm-message]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.confirmed === 'true') {
                delete form.dataset.confirmed;

                return;
            }

            event.preventDefault();
            open(form);
        });
    });

    acceptButton.addEventListener('click', () => {
        if (!pendingForm) {
            close();

            return;
        }

        pendingForm.dataset.confirmed = 'true';
        pendingForm.requestSubmit();
    });
    cancelButton.addEventListener('click', close);
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            close();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !dialog.classList.contains('hidden')) {
            close();
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

        const syncProductRepaymentFrequency = () => {
            const productFrequency = product?.selectedOptions?.[0]?.dataset?.repaymentFrequency;

            if (!frequency || !['daily', 'weekly', 'monthly'].includes(productFrequency)) {
                return;
            }

            frequency.value = productFrequency;
            setUnit();
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
            syncProductRepaymentFrequency();
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
    const interestField = document.querySelector('[data-report-interest-field]');

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

            if (interestField) {
                interestField.hidden = button.dataset.reportProfitability !== '1';
                interestField.querySelectorAll('select').forEach((select) => {
                    select.disabled = interestField.hidden;
                    select.value = button.dataset.reportInterestBasis || 'collected';
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

const initTellerLookup = () => {
    const modal = document.querySelector('[data-teller-lookup-modal]');
    const openButton = document.querySelector('[data-teller-lookup-open]');
    const closeButtons = document.querySelectorAll('[data-teller-lookup-close]');
    const selectForms = document.querySelectorAll('[data-teller-lookup-select]');
    const loadingOverlay = document.querySelector('[data-teller-lookup-loading]');
    const pageScope = document.querySelector('[data-teller-lookup-scope]');
    const modalScope = document.querySelector('[data-teller-lookup-modal-scope]');

    if (!modal || !openButton) {
        return;
    }

    const open = () => {
        if (pageScope && modalScope) {
            modalScope.value = pageScope.value;
        }

        modal.classList.remove('hidden');
        modal.classList.add('grid');
        modal.querySelector('input[name="account_search"]')?.focus();
    };

    const close = () => {
        modal.classList.add('hidden');
        modal.classList.remove('grid');
    };

    const showLoading = () => {
        close();
        loadingOverlay?.classList.remove('hidden');
        loadingOverlay?.classList.add('grid');
        document.body.classList.add('overflow-hidden');
    };

    openButton.addEventListener('click', open);
    closeButtons.forEach((button) => button.addEventListener('click', close));
    selectForms.forEach((form) => form.addEventListener('submit', showLoading));
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

    if (modal.dataset.open === '1') {
        open();
    }
};

const initLoanRepairModal = () => {
    const modal = document.querySelector('[data-loan-repair-modal]');
    const openButton = document.querySelector('[data-loan-repair-open]');
    const closeButtons = document.querySelectorAll('[data-loan-repair-close]');

    if (!modal || !openButton) {
        return;
    }

    const open = () => {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.querySelector('[name="loan_product_id"]')?.focus();
    };

    const close = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    openButton.addEventListener('click', open);
    closeButtons.forEach((button) => button.addEventListener('click', close));
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

    if (modal.dataset.open === '1') {
        open();
    }
};

const initLoanReopenModal = () => {
    const modal = document.querySelector('[data-loan-reopen-modal]');
    const openButton = document.querySelector('[data-loan-reopen-open]');
    const closeButtons = document.querySelectorAll('[data-loan-reopen-close]');

    if (!modal || !openButton) {
        return;
    }

    const open = () => {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.querySelector('[name="reason"]')?.focus();
    };

    const close = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    openButton.addEventListener('click', open);
    closeButtons.forEach((button) => button.addEventListener('click', close));
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

    if (modal.dataset.open === '1') {
        open();
    }
};

const initLoanCoverageModal = () => {
    const modal = document.querySelector('[data-loan-coverage-modal]');
    const openButton = document.querySelector('[data-loan-coverage-open]');
    const closeButtons = document.querySelectorAll('[data-loan-coverage-close]');

    if (!modal || !openButton) {
        return;
    }

    const open = () => {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.querySelector('[name="coverage_method"]')?.focus();
    };

    const close = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    openButton.addEventListener('click', open);
    closeButtons.forEach((button) => button.addEventListener('click', close));
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

    if (modal.dataset.open === '1') {
        open();
    }
};

const initLedgerAccountModals = () => {
    const modals = document.querySelectorAll('[data-ledger-modal]');
    const openButtons = document.querySelectorAll('[data-ledger-modal-open]');

    if (!modals.length || !openButtons.length) {
        return;
    }

    const open = (modal) => {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.querySelector('input:not([type="hidden"]), select, textarea')?.focus();
    };

    const close = (modal) => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    const closeAll = () => modals.forEach(close);

    openButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.getElementById(button.dataset.ledgerModalOpen || '');

            if (modal) {
                open(modal);
            }
        });
    });

    modals.forEach((modal) => {
        modal.querySelectorAll('[data-ledger-modal-close]').forEach((button) => {
            button.addEventListener('click', () => close(modal));
        });
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                close(modal);
            }
        });

        if (modal.dataset.open === '1') {
            open(modal);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAll();
        }
    });
};

const initDataWipeTargets = () => {
    const scope = document.querySelector('[name="scope_type"]');
    const targets = document.querySelectorAll('[data-wipe-target]');
    const branchTarget = document.querySelector('[data-wipe-target="branch"]');
    const userTarget = document.querySelector('[data-wipe-target="user"]');
    const categoryInputs = document.querySelectorAll('[data-wipe-category]');
    const markButtons = document.querySelectorAll('[data-wipe-mark]');

    if (!scope || !targets.length) {
        return;
    }

    const syncCategoryStates = () => {
        categoryInputs.forEach((input) => {
            const state = input.closest('label')?.querySelector('[data-wipe-state]');

            if (state) {
                state.textContent = input.checked ? 'Clear/delete' : 'Leave';
                state.classList.toggle('bg-red-50', input.checked);
                state.classList.toggle('text-red-700', input.checked);
                state.classList.toggle('bg-slate-100', !input.checked);
                state.classList.toggle('text-slate-600', !input.checked);
            }
        });
    };

    const syncTargets = () => {
        targets.forEach((target) => {
            const active = target.dataset.wipeTarget === scope.value
                || (target.dataset.wipeTarget === 'branch' && scope.value === 'user');
            target.disabled = !active;
            target.closest('label').hidden = !active;
        });

        syncUserOptions();
    };

    const syncUserOptions = () => {
        if (!branchTarget || !userTarget) {
            return;
        }

        const branchId = branchTarget.value;
        const options = [...userTarget.options];
        let firstVisible = null;

        options.forEach((option) => {
            const visible = !branchId || option.dataset.branchId === branchId;
            option.hidden = !visible;
            option.disabled = !visible;

            if (visible && !firstVisible) {
                firstVisible = option;
            }
        });

        if (userTarget.selectedOptions[0]?.disabled && firstVisible) {
            userTarget.value = firstVisible.value;
        }
    };

    scope.addEventListener('change', syncTargets);
    branchTarget?.addEventListener('change', syncUserOptions);
    categoryInputs.forEach((input) => input.addEventListener('change', syncCategoryStates));
    markButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const shouldClear = button.dataset.wipeMark === 'clear';
            categoryInputs.forEach((input) => {
                input.checked = shouldClear;
            });
            syncCategoryStates();
        });
    });
    syncTargets();
    syncCategoryStates();
};

const initResponsiveTables = () => {
    document.querySelectorAll('main table').forEach((table) => {
        if (table.dataset.mobileReady === '1') {
            return;
        }

        const headings = [...table.querySelectorAll('thead th')].map((heading) => heading.textContent.trim());

        table.querySelectorAll('tbody tr').forEach((row) => {
            row.querySelectorAll('td').forEach((cell, index) => {
                if (!cell.dataset.label && headings[index]) {
                    cell.dataset.label = headings[index];
                }
            });
        });

        table.dataset.mobileReady = '1';
    });
};

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initSteps();
    initRipples();
    initKeyboardFocus();
    initSidebar();
    initConfirmDialog();
    initLoanSchedulePreview();
    initReportLauncher();
    initTellerLookup();
    initLoanRepairModal();
    initLoanReopenModal();
    initLoanCoverageModal();
    initLedgerAccountModals();
    initDataWipeTargets();
    initResponsiveTables();
});
