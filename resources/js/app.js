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

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initSteps();
    initRipples();
    initKeyboardFocus();
});
