(() => {
    const forms = [
        {
            id: "registration-form",
            action: "register",
            statusId: "registration-status",
            successMessage: "Your DETORA account was created successfully."
        },
        {
            id: "booking-form",
            action: "booking",
            statusId: "booking-status",
            successMessage: "Your booking request was received."
        }
    ];

    async function getCsrfToken(apiUrl) {
        const response = await fetch(`${apiUrl}?action=csrf`, {
            credentials: "same-origin",
            headers: { Accept: "application/json" }
        });
        const result = await response.json();

        if (!response.ok || !result.csrf_token) {
            throw new Error(result.error || "Could not start a secure form session.");
        }

        return result.csrf_token;
    }

    for (const config of forms) {
        const form = document.getElementById(config.id);
        if (!form) continue;

        const status = document.getElementById(config.statusId);
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;

            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            status.textContent = "Submitting...";
            status.dataset.state = "pending";

            try {
                const apiUrl = form.dataset.api;
                const csrfToken = await getCsrfToken(apiUrl);
                const payload = Object.fromEntries(new FormData(form).entries());
                payload.action = config.action;
                payload.csrf_token = csrfToken;

                const response = await fetch(apiUrl, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify(payload)
                });
                const result = await response.json();

                if (!response.ok) {
                    throw new Error(result.error || "The form could not be submitted.");
                }

                status.textContent = config.successMessage;
                status.dataset.state = "success";
                form.reset();
            } catch (error) {
                status.textContent = error.message || "Could not connect to the server. Please try again.";
                status.dataset.state = "error";
            } finally {
                button.disabled = false;
            }
        });
    }
})();
