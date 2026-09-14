import './traffic';

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

const replaceSelectOptions = (select, items, placeholder, selectedValue = '') => {
    if (!select) {
        return;
    }

    select.replaceChildren(new Option(placeholder, ''));
    items.forEach((item) => select.add(new Option(item.name, String(item.id), false, String(item.id) === String(selectedValue))));
};

const initUserForms = () => {
    document.querySelectorAll('[data-user-form]').forEach((form) => {
        const organization = form.querySelector('[data-user-organization]');
        const branch = form.querySelector('[data-user-branch]');
        const role = form.querySelector('[data-user-role]');
        const passwordToggle = form.querySelector('[data-user-password-toggle]');
        const passwordFields = form.querySelector('[data-user-password-fields]');

        passwordToggle?.addEventListener('change', () => {
            passwordFields.classList.toggle('hidden', !passwordToggle.checked);
            passwordFields.querySelectorAll('input').forEach((input) => {
                input.disabled = !passwordToggle.checked;
                input.required = passwordToggle.checked;

                if (!passwordToggle.checked) {
                    input.value = '';
                }
            });
        });

        if (!organization || !form.dataset.userOptionsUrl) {
            return;
        }

        organization.addEventListener('change', async () => {
            branch.disabled = true;
            role.disabled = true;

            try {
                const url = new URL(form.dataset.userOptionsUrl, window.location.origin);
                url.searchParams.set('organization_id', organization.value);
                const response = await fetch(url, { headers: { Accept: 'application/json' } });

                if (!response.ok) {
                    throw new Error('Unable to load organization options.');
                }

                const options = await response.json();
                replaceSelectOptions(branch, options.branches || [], 'No branch / all branches');
                replaceSelectOptions(role, options.roles || [], 'Select role');
            } catch {
                replaceSelectOptions(branch, [], 'Unable to load branches');
                replaceSelectOptions(role, [], 'Unable to load roles');
            } finally {
                branch.disabled = false;
                role.disabled = false;
            }
        });
    });
};

const initFlashToasts = () => {
    document.querySelectorAll('[data-flash-toast]').forEach((toast) => {
        toast.querySelector('[data-flash-dismiss]')?.addEventListener('click', () => {
            toast.remove();
        });
    });
};

let chatAudioContext = null;

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

const unlockChatAudio = () => {
    if (!window.AudioContext && !window.webkitAudioContext) {
        return;
    }

    chatAudioContext = chatAudioContext || new (window.AudioContext || window.webkitAudioContext)();
    chatAudioContext.resume?.();
};

const playChatSound = () => {
    if (!chatAudioContext) {
        return;
    }

    const oscillator = chatAudioContext.createOscillator();
    const gain = chatAudioContext.createGain();

    oscillator.type = 'sine';
    oscillator.frequency.setValueAtTime(880, chatAudioContext.currentTime);
    oscillator.frequency.exponentialRampToValueAtTime(660, chatAudioContext.currentTime + 0.12);
    gain.gain.setValueAtTime(0.001, chatAudioContext.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.12, chatAudioContext.currentTime + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.001, chatAudioContext.currentTime + 0.22);
    oscillator.connect(gain);
    gain.connect(chatAudioContext.destination);
    oscillator.start();
    oscillator.stop(chatAudioContext.currentTime + 0.24);
};

const setChatUnreadCount = (count) => {
    document.querySelectorAll('[data-chat-unread-count]').forEach((badge) => {
        badge.textContent = count > 99 ? '99+' : String(count || '');
        badge.classList.toggle('hidden', count <= 0);
        badge.classList.toggle('grid', count > 0);
    });
};

const showChatToast = (message) => {
    const region = document.querySelector('[data-chat-toast-region]');

    if (!region) {
        return;
    }

    const toast = document.createElement('a');
    toast.href = message.conversation_url;
    toast.className = 'block rounded-xl border border-brand-200 bg-white p-4 text-sm shadow-2xl ring-1 ring-slate-950/5 hover:bg-brand-50';

    const sender = document.createElement('div');
    sender.className = 'font-semibold text-slate-950';
    sender.textContent = message.sender;

    const context = document.createElement('div');
    context.className = 'mt-1 text-xs font-medium text-brand-700';
    context.textContent = message.context;

    const body = document.createElement('div');
    body.className = 'mt-2 line-clamp-2 text-slate-600';
    body.textContent = message.body;

    toast.append(sender, context, body);
    region.prepend(toast);
    window.setTimeout(() => toast.remove(), 9000);
};

const initChatNotifications = () => {
    const pollUrl = document.body.dataset.chatPollUrl;

    if (!pollUrl) {
        return;
    }

    ['click', 'keydown', 'touchstart'].forEach((eventName) => {
        document.addEventListener(eventName, unlockChatAudio, { once: true, passive: true });
    });

    let latestMessageId = Number(document.body.dataset.chatLatestId || 0);
    const poll = async () => {
        try {
            const response = await fetch(`${pollUrl}?after_id=${latestMessageId}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            setChatUnreadCount(Number(payload.unread_count || 0));

            const toastMessages = (payload.messages || []).filter((message) => {
                const url = new URL(message.conversation_url, window.location.origin);

                return url.pathname !== window.location.pathname;
            });

            if (toastMessages.length > 0) {
                toastMessages.forEach(showChatToast);
                playChatSound();
            }

            latestMessageId = Math.max(latestMessageId, Number(payload.latest_message_id || 0), ...((payload.messages || []).map((message) => Number(message.id || 0))));
            document.body.dataset.chatLatestId = String(latestMessageId);
        } catch {
            // Keep polling resilient if the network drops briefly.
        }
    };

    window.setInterval(poll, 5000);
};

const createChatMessageElement = (message) => {
    const wrapper = document.createElement('div');
    wrapper.className = `mb-4 flex ${message.is_mine ? 'justify-end' : 'justify-start'}`;
    wrapper.dataset.chatMessageId = message.id;

    const bubble = document.createElement('div');
    bubble.className = `max-w-[min(44rem,92%)] rounded-xl px-4 py-3 shadow-sm ${message.is_mine ? 'bg-brand-700 text-white' : 'border border-slate-200 bg-white text-slate-900'}`;

    const sender = document.createElement('div');
    sender.className = `text-xs font-semibold ${message.is_mine ? 'text-brand-50' : 'text-slate-500'}`;
    sender.textContent = message.sender;

    bubble.append(sender);

    if ((message.body || '').trim() !== '') {
        const body = document.createElement('div');
        body.className = 'mt-1 whitespace-pre-wrap text-sm leading-6';
        body.textContent = message.body;
        bubble.append(body);
    }

    if (message.attachment?.url) {
        const mime = message.attachment.mime || '';

        if (mime.startsWith('image/') || message.attachment.type === 'photo') {
            const link = document.createElement('a');
            link.href = message.attachment.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.className = 'mt-3 block';

            const image = document.createElement('img');
            image.src = message.attachment.url;
            image.alt = message.attachment.name || 'Chat photo';
            image.className = 'max-h-80 rounded-lg object-contain';

            link.append(image);
            bubble.append(link);
        } else {
            const audio = document.createElement('audio');
            audio.controls = true;
            audio.src = message.attachment.url;
            audio.className = 'mt-3 w-full max-w-full';
            bubble.append(audio);
        }
    }

    const time = document.createElement('div');
    time.className = `mt-2 text-[0.7rem] ${message.is_mine ? 'text-brand-50/80' : 'text-slate-400'}`;
    time.textContent = message.time || '';

    bubble.append(time);
    wrapper.append(bubble);

    return wrapper;
};

const initChatVoiceRecording = (scope) => {
    const recordButton = scope.querySelector('[data-chat-record]');
    const attachmentInput = scope.querySelector('[data-chat-attachment]');
    const status = scope.querySelector('[data-chat-record-status]');

    if (!recordButton || !attachmentInput) {
        return;
    }

    const setStatus = (message) => {
        if (status) {
            status.textContent = message;
        }
    };

    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder || !window.DataTransfer) {
        recordButton.disabled = true;
        setStatus('Voice recording unavailable');

        return;
    }

    let recorder = null;
    let chunks = [];

    attachmentInput.addEventListener('change', () => {
        setStatus(attachmentInput.files?.[0]?.name || '');
    });

    recordButton.addEventListener('click', async () => {
        if (recorder?.state === 'recording') {
            recorder.stop();

            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            chunks = [];
            recorder = new MediaRecorder(stream);

            recorder.addEventListener('dataavailable', (event) => {
                if (event.data.size > 0) {
                    chunks.push(event.data);
                }
            });

            recorder.addEventListener('stop', () => {
                stream.getTracks().forEach((track) => track.stop());

                const blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
                const file = new File([blob], `voice-note-${Date.now()}.webm`, { type: blob.type || 'audio/webm' });
                const transfer = new DataTransfer();
                transfer.items.add(file);
                attachmentInput.files = transfer.files;
                recordButton.textContent = 'Record Voice';
                setStatus('Voice note ready');
            });

            recorder.start();
            recordButton.textContent = 'Stop Recording';
            setStatus('Recording...');
        } catch {
            setStatus('Microphone unavailable');
        }
    });
};

const initChatConversation = () => {
    const scope = document.querySelector('[data-chat-conversation]');

    if (!scope) {
        return;
    }

    const messagesUrl = scope.dataset.messagesUrl;
    const sendUrl = scope.dataset.sendUrl;
    const messageList = scope.querySelector('[data-chat-messages]');
    const form = scope.querySelector('[data-chat-form]');
    const input = scope.querySelector('[data-chat-input]');
    const attachmentInput = scope.querySelector('[data-chat-attachment]');
    const recordStatus = scope.querySelector('[data-chat-record-status]');
    let lastMessageId = Math.max(0, ...[...scope.querySelectorAll('[data-chat-message-id]')].map((element) => Number(element.dataset.chatMessageId || 0)));

    const scrollToBottom = () => {
        if (messageList) {
            messageList.scrollTop = messageList.scrollHeight;
        }
    };

    const appendMessages = (messages) => {
        let hasIncoming = false;

        messages.forEach((message) => {
            if (Number(message.id) <= lastMessageId) {
                return;
            }

            messageList?.append(createChatMessageElement(message));
            lastMessageId = Math.max(lastMessageId, Number(message.id));
            hasIncoming = hasIncoming || !message.is_mine;
        });

        if (messages.length > 0) {
            scrollToBottom();
        }

        if (hasIncoming) {
            playChatSound();
        }
    };

    const fetchMessages = async () => {
        try {
            const response = await fetch(`${messagesUrl}?after_id=${lastMessageId}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            appendMessages(payload.messages || []);
            setChatUnreadCount(Number(payload.unread_count || 0));
        } catch {
            // Keep the conversation usable if one poll fails.
        }
    };

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!input?.value.trim() && !attachmentInput?.files?.length) {
            return;
        }

        const formData = new FormData(form);

        try {
            const response = await fetch(sendUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: formData,
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message || 'Message could not be sent.');
            }

            appendMessages([payload.message]);
            setChatUnreadCount(Number(payload.unread_count || 0));
            form.reset();
            if (recordStatus) {
                recordStatus.textContent = '';
            }
        } catch (error) {
            window.alert(error.message);
        }
    });

    initChatVoiceRecording(scope);
    scrollToBottom();
    window.setInterval(fetchMessages, 3000);
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
        acceptButton.disabled = false;
        pendingForm = null;
    };

    const open = (form) => {
        const tone = form.dataset.confirmTone || 'warning';
        pendingForm = form;
        title.textContent = form.dataset.confirmTitle || 'Confirm action';
        message.textContent = form.dataset.confirmMessage || 'Please confirm that you want to continue.';
        acceptButton.textContent = form.dataset.confirmLabel || 'Confirm';
        acceptButton.disabled = false;
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

        if (acceptButton.disabled) {
            return;
        }

        const form = pendingForm;
        acceptButton.disabled = true;
        pendingForm = null;
        form.dataset.confirmed = 'true';
        form.requestSubmit();
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
    const description = document.querySelector('[data-report-modal-description]');
    const dateFields = document.querySelector('[data-report-date-fields]');
    const detailField = document.querySelector('[data-report-detail-field]');
    const interestField = document.querySelector('[data-report-interest-field]');
    const ledgerField = document.querySelector('[data-report-ledger-field]');
    const ledgerSingleField = document.querySelector('[data-report-ledger-single-field]');
    const ledgerMultipleField = document.querySelector('[data-report-ledger-multiple-field]');
    const postedByField = document.querySelector('[data-report-posted-by-field]');

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

            if (description) {
                description.textContent = button.dataset.reportDescription || 'Choose the filters, then generate the report.';
            }

            if (dateFields) {
                dateFields.hidden = button.dataset.reportDates !== '1';
                dateFields.querySelectorAll('input').forEach((input) => {
                    input.disabled = dateFields.hidden;

                    if (input.name === 'from') {
                        input.value = button.dataset.reportFrom || input.value;
                    }

                    if (input.name === 'to') {
                        input.value = button.dataset.reportTo || input.value;
                    }
                });
            }

            if (postedByField) {
                const isArrearsRate = button.dataset.reportArrearsRate === '1';
                postedByField.hidden = isArrearsRate;
                postedByField.querySelectorAll('select').forEach((select) => {
                    select.disabled = isArrearsRate;
                    if (isArrearsRate) select.value = '';
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

            if (ledgerField) {
                const showLedgerField = button.dataset.reportLedger === '1';
                const ledgerMode = button.dataset.reportLedgerMode || 'single';

                ledgerField.hidden = !showLedgerField;
                ledgerSingleField?.classList.toggle('hidden', !showLedgerField || ledgerMode === 'multiple');
                ledgerMultipleField?.classList.toggle('hidden', !showLedgerField || ledgerMode !== 'multiple');
                ledgerSingleField?.querySelectorAll('select').forEach((select) => {
                    select.disabled = !showLedgerField || ledgerMode === 'multiple';
                });
                ledgerMultipleField?.querySelectorAll('select').forEach((select) => {
                    select.disabled = !showLedgerField || ledgerMode !== 'multiple';
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

const initNonCashLookups = () => {
    const modals = document.querySelectorAll('[data-non-cash-lookup-modal]');
    const openButtons = document.querySelectorAll('[data-non-cash-lookup-open]');

    if (!modals.length || !openButtons.length) {
        return;
    }

    const close = (modal) => {
        modal.classList.add('hidden');
        modal.classList.remove('grid');
    };

    const open = (modal) => {
        modal.classList.remove('hidden');
        modal.classList.add('grid');
        modal.querySelector('input:not([type="hidden"])')?.focus();
    };

    openButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const modal = [...modals].find((candidate) => candidate.dataset.nonCashLookupModal === button.dataset.nonCashLookupOpen);

            if (modal) {
                open(modal);
            }
        });
    });

    modals.forEach((modal) => {
        modal.querySelectorAll('[data-non-cash-lookup-close]').forEach((button) => button.addEventListener('click', () => close(modal)));
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
            modals.forEach(close);
        }
    });
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

const initTableExports = () => {
    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    const fileSafe = (value) => String(value || 'export')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 80) || 'export';
    const tableTitle = (table, index) => {
        const explicit = table.dataset.exportTitle;

        if (explicit) {
            return explicit;
        }

        const panel = table.closest('.df-panel, section, article, main');
        const heading = panel?.querySelector('h1, h2, h3') || document.querySelector('main h1');

        return heading?.textContent?.trim() || `Table ${index + 1}`;
    };
    const cellText = (cell) => cell.textContent.replace(/\s+/g, ' ').trim();
    const rowsFor = (table) => [...table.querySelectorAll('tr')]
        .map((row) => [...row.children]
            .filter((cell) => !cell.matches('[data-export-exclude]'))
            .map(cellText))
        .filter((row) => row.some((value) => value !== ''));
    const csvFor = (rows) => rows
        .map((row) => row.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(','))
        .join('\r\n');
    const download = (content, filename, type) => {
        const blob = new Blob([content], { type });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    };
    const exportBrand = (title) => {
        const reportOrganization = document.querySelector('[data-report-organization]');
        const sidebarLogo = document.querySelector('#app-sidebar img');
        const sidebarName = document.querySelector('#app-sidebar .font-semibold');
        const branchLabel = document.querySelector('header span.text-slate-500');
        const now = new Date();
        const date = now.toLocaleDateString();
        const stamp = now.toISOString().replace(/[-:T.Z]/g, '').slice(0, 12);

        return {
            logo: reportOrganization?.dataset.reportLogo
                ? (/^(https?:|data:|\/)/.test(reportOrganization.dataset.reportLogo) ? reportOrganization.dataset.reportLogo : `/storage/${reportOrganization.dataset.reportLogo.replace(/^storage\//, '')}`)
                : sidebarLogo?.getAttribute('src') || '/brand/digitofina-logo.svg',
            organization: reportOrganization?.dataset.reportOrganization || sidebarLogo?.getAttribute('alt') || sidebarName?.textContent?.trim() || 'DigitoFina',
            address: reportOrganization?.dataset.reportAddress || '',
            phone: reportOrganization?.dataset.reportPhone || '',
            email: reportOrganization?.dataset.reportEmail || '',
            website: reportOrganization?.dataset.reportWebsite || '',
            reportNumber: `WEB-${stamp}`,
            branch: branchLabel?.textContent?.trim() || 'Current branch',
            currency: 'Uganda Shilling - UGX',
            period: `${date} - ${date}`,
            heading: `${title} As At: ${date}`,
        };
    };
    const metadataRows = (brand, title) => [
        ['Report Name', title],
        ['Report Number', brand.reportNumber],
        ['Currency', brand.currency],
        ['Branch', brand.branch],
            ['Period', brand.period],
            ...(brand.address ? [['Address', brand.address]] : []),
            ...(brand.phone ? [['Phone', brand.phone]] : []),
            ...(brand.email ? [['Email', brand.email]] : []),
            ...(brand.website ? [['Website', brand.website]] : []),
            ['Generated', now.toLocaleString()],
    ];
    const excelFor = (title, rows) => {
        const brand = exportBrand(title);
        const span = Math.max(rows[0]?.length || 1, 5);

        return `
        <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
        <head>
            <meta charset="utf-8">
            <style>
                body { font-family: Arial, sans-serif; color: #111827; }
                .brand-logo { max-height: 82px; max-width: 280px; }
                .brand-name { font-size: 16px; font-weight: 700; text-transform: uppercase; }
                .meta-label { font-family: "Courier New", monospace; font-weight: 700; }
                .meta-value { font-family: "Courier New", monospace; }
                .title { font-size: 16px; font-weight: 700; text-align: center; }
                .report-table { border-collapse: collapse; width: 100%; }
                .report-table th, .report-table td { border: 1px solid #1f2937; padding: 7px 9px; vertical-align: top; }
                .report-table th { background: #e5e7eb; text-align: center; text-transform: uppercase; }
            </style>
        </head>
        <body>
            <table>
                <tr>
                    <td colspan="2" rowspan="5">
                        <img class="brand-logo" src="${escapeHtml(brand.logo)}" alt="${escapeHtml(brand.organization)}">
                        <div class="brand-name">${escapeHtml(brand.organization)}</div>
                    </td>
                    <td></td>
                    <td class="meta-label">Report Name:</td>
                    <td class="meta-value">${escapeHtml(title)}</td>
                </tr>
                ${metadataRows(brand, title).slice(1).map(([label, value]) => `
                    <tr><td></td><td class="meta-label">${escapeHtml(label)}:</td><td class="meta-value">${escapeHtml(value)}</td></tr>
                `).join('')}
                <tr><td colspan="${span}" class="title">${escapeHtml(brand.heading)}</td></tr>
            </table>
            <table class="report-table">
                ${rows.map((row, rowIndex) => `<tr>${row.map((value) => `<${rowIndex === 0 ? 'th' : 'td'}>${escapeHtml(value)}</${rowIndex === 0 ? 'th' : 'td'}>`).join('')}</tr>`).join('')}
            </table>
        </body>
        </html>
    `;
    };
    const printPdf = (title, rows) => {
        const brand = exportBrand(title);
        const documentHtml = `
            <!doctype html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>${escapeHtml(title)}</title>
                <style>
                    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                    body { font-family: Arial, sans-serif; color: #0f172a; margin: 24px; }
                    .document-header { align-items: flex-start; display: grid; gap: 24px; grid-template-columns: 1fr 1fr; margin-bottom: 14px; }
                    .brand img { display: block; max-height: 82px; max-width: 280px; object-fit: contain; }
                    .brand-name { color: #111827; font-size: 16px; font-weight: 700; margin-top: 6px; text-transform: uppercase; }
                    .metadata { display: grid; font-family: "Courier New", monospace; font-size: 13px; gap: 6px 14px; grid-template-columns: 160px minmax(220px, 1fr); justify-content: end; }
                    .meta-label { font-weight: 700; }
                    h1 { font-size: 16px; margin: 18px 0 7px; text-align: center; }
                    table { border-collapse: collapse; width: 100%; font-size: 11px; }
                    th, td { border: 1px solid #1f2937; padding: 7px 9px; text-align: left; vertical-align: top; }
                    th { background: #e5e7eb; text-align: center; text-transform: uppercase; }
                    tr:nth-child(even) td { background: #f8fafc; }
                    @page { size: landscape; margin: 14mm; }
                </style>
            </head>
            <body>
                <header class="document-header">
                    <section class="brand">
                        <img src="${escapeHtml(brand.logo)}" alt="${escapeHtml(brand.organization)}">
                        <div class="brand-name">${escapeHtml(brand.organization)}</div>
                    </section>
                    <section class="metadata">
                        ${metadataRows(brand, title).map(([label, value]) => `<div class="meta-label">${escapeHtml(label)}:</div><div>${escapeHtml(value)}</div>`).join('')}
                    </section>
                </header>
                <h1>${escapeHtml(brand.heading)}</h1>
                <table>${rows.map((row, rowIndex) => `
                    <tr>${row.map((value) => `<${rowIndex === 0 ? 'th' : 'td'}>${escapeHtml(value)}</${rowIndex === 0 ? 'th' : 'td'}>`).join('')}</tr>
                `).join('')}</table>
                <script>window.addEventListener('load', () => { window.print(); });<\/script>
            </body>
            </html>
        `;
        const printWindow = window.open('', '_blank', 'width=1100,height=800');

        if (printWindow) {
            printWindow.document.write(documentHtml);
            printWindow.document.close();

            return;
        }

        const frame = document.createElement('iframe');
        frame.style.position = 'fixed';
        frame.style.right = '0';
        frame.style.bottom = '0';
        frame.style.width = '0';
        frame.style.height = '0';
        frame.style.border = '0';
        document.body.appendChild(frame);

        const frameWindow = frame.contentWindow;

        if (!frameWindow) {
            frame.remove();

            return;
        }

        frame.addEventListener('load', () => {
            frameWindow.focus();
            frameWindow.print();
            setTimeout(() => frame.remove(), 1000);
        }, { once: true });
        frameWindow.document.open();
        frameWindow.document.write(documentHtml);
        frameWindow.document.close();
    };

    document.querySelectorAll('main table:not([data-exportable="false"])').forEach((table, index) => {
        if (table.dataset.exportReady === '1') {
            return;
        }

        const rows = rowsFor(table);

        if (rows.length === 0) {
            return;
        }

        const title = tableTitle(table, index);
        const toolbar = document.createElement('div');
        toolbar.className = 'mb-2 flex flex-wrap items-center justify-end gap-2 rounded-md bg-slate-50 px-4 py-2 text-xs';
        toolbar.dataset.tableExportToolbar = '1';
        toolbar.innerHTML = `
            <span class="mr-auto pl-2 font-semibold text-slate-700">Export table</span>
            <button type="button" class="rounded border border-emerald-700 bg-emerald-600 px-3 py-1.5 font-semibold text-white shadow-sm hover:bg-emerald-700" data-table-export="csv">CSV</button>
            <button type="button" class="rounded border border-blue-700 bg-blue-600 px-3 py-1.5 font-semibold text-white shadow-sm hover:bg-blue-700" data-table-export="xls">Excel</button>
            <button type="button" class="mr-2 rounded border border-rose-700 bg-rose-600 px-3 py-1.5 font-semibold text-white shadow-sm hover:bg-rose-700" data-table-export="pdf">PDF</button>
        `;

        const tableParent = table.parentElement;
        const insertHost = tableParent?.classList.contains('overflow-auto') ? tableParent.parentElement : tableParent;
        const insertBefore = tableParent?.classList.contains('overflow-auto') ? tableParent : table;

        insertHost?.insertBefore(toolbar, insertBefore);
        toolbar.querySelectorAll('[data-table-export]').forEach((button) => {
            button.addEventListener('click', () => {
                const currentRows = rowsFor(table);
                const filename = `${fileSafe(title)}-${new Date().toISOString().slice(0, 10)}`;

                if (button.dataset.tableExport === 'csv') {
                    download(csvFor(currentRows), `${filename}.csv`, 'text/csv;charset=utf-8');
                } else if (button.dataset.tableExport === 'xls') {
                    download(excelFor(title, currentRows), `${filename}.xls`, 'application/vnd.ms-excel;charset=utf-8');
                } else {
                    printPdf(title, currentRows);
                }
            });
        });

        table.dataset.exportReady = '1';
    });
};

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initSteps();
    initRipples();
    initKeyboardFocus();
    initUserForms();
    initFlashToasts();
    initChatNotifications();
    initChatConversation();
    initSidebar();
    initConfirmDialog();
    initLoanSchedulePreview();
    initReportLauncher();
    initTellerLookup();
    initNonCashLookups();
    initLoanRepairModal();
    initLoanReopenModal();
    initLoanCoverageModal();
    initLedgerAccountModals();
    initDataWipeTargets();
    initTableExports();
    initResponsiveTables();
});
