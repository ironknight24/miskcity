document.addEventListener("DOMContentLoaded", () => {
    // Remove profile (hidden trigger)
    document.getElementById("remove-profile-button")
        ?.addEventListener("click", () =>
            document.getElementById("ajax-trigger-hidden")?.click()
        );

    // Show delete icons
    document.querySelector('.showDeleteIcons')?.addEventListener('click', (e) => {
        e.preventDefault();

        document.querySelector('.swap-off')?.classList.toggle('hidden');
        document.querySelector('.swap-on')?.classList.toggle('hidden');

        const deleteIcons = document.querySelectorAll('.deleteRedIcon');
        for (const el of deleteIcons) {
            el.classList.toggle('hidden');
        }
    });

    // Family member delete modal
    const hiddenInput = document.getElementById("delete-nid");
    const modalButtons = document.querySelectorAll('[data-modal-toggle]');

    for (const btn of modalButtons) {
        btn.addEventListener('click', () => {
            hiddenInput.value = btn.dataset.nid;
        });
    }

    document.querySelector(".confirm-delete-btn")?.addEventListener("click", () => {
        deleteFamilyMember(hiddenInput.value);
    });

    // Logout
    document.addEventListener("click", (e) => {
        if (e.target.closest(".confirm-logout-btn")) {
            e.preventDefault();
            location.href = drupalSettings.globalVariables.webportalUrl + "/logout";
        }
    });
});

/* ===================== API Calls ===================== */

function deleteFamilyMember(nid) {
    fetch(drupalSettings.globalVariables.webportalUrl + "postData", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            endPoint: `family-members/delete-family-member/${nid}`,
            service: "tiotcitizenapp",
            type: 2,
        }),
    })
        .then(res => res.json())
        .then(res => {
            const box = document.querySelector(".successOrFailure");
            box.classList.remove("hidden");

            if (res.status) {
                document.querySelector(`[disable-nid="${nid}"]`)?.classList.add("hidden");
                showSuccessMessage("Family member deleted successfully");
            } else {
                showFailureMessage("Family member not deleted successfully");
            }

            setTimeout(() => box.classList.add("hidden"), 5000);
        });
}
