const traffic = document.querySelector('meta[name="df-traffic"]');
if (traffic) {
    let lastActivity = Date.now();
    for (const event of ['pointerdown', 'keydown', 'scroll']) {
        window.addEventListener(event, () => { lastActivity = Date.now(); }, { passive: true });
    }
    const heartbeat = () => {
        if (document.visibilityState !== 'visible' || Date.now() - lastActivity > 120000) return;
        fetch(traffic.dataset.endpoint, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': traffic.dataset.csrf },
            body: JSON.stringify({ visit_id: Number(traffic.content) }),
        }).catch(() => {});
    };
    window.setInterval(heartbeat, 30000);
}
