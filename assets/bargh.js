function persianToEnglishNumbers(str) {
    if (typeof str !== 'string') return str;
    const persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    const englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    for (let i = 0; i < 10; i++) {
        str = str.replace(new RegExp(persianNumbers[i], 'g'), englishNumbers[i]);
    }
    return str;
}

function filterTable() {
    const input = document.getElementById("searchInput");
    if (!input) return;
    const filterText = persianToEnglishNumbers(input.value.trim()).toUpperCase();
    const cardList = document.querySelector(".outage-card-list");
    if (!cardList) return;
    const cards = cardList.getElementsByTagName("li");

    if (filterText === "") {
        for (let i = 0; i < cards.length; i++) {
            cards[i].style.display = "";
        }
        return;
    }

    const keywords = filterText.split(/\s+/).filter(Boolean);
    for (let i = 0; i < cards.length; i++) {
        const addressCell = cards[i].querySelector(".card-address");
        let showCard = false;
        if (addressCell) {
            const cellText = (addressCell.textContent || addressCell.innerText).toUpperCase();
            let matchAllKeywords = true;
            for (let j = 0; j < keywords.length; j++) {
                if (cellText.indexOf(keywords[j]) === -1) {
                    matchAllKeywords = false;
                    break;
                }
            }
            if (matchAllKeywords) {
                showCard = true;
            }
        }
        cards[i].style.display = showCard ? "" : "none";
    }
}

function toggleGuestStar(starElement, addressHash) {
    if (typeof currentGuestIdentifier === 'undefined' || currentGuestIdentifier === null) {
        console.error("currentGuestIdentifier is not defined.");
        alert("خطا: امکان ذخیره علاقه‌مندی وجود ندارد.");
        return;
    }

    if (!addressHash || typeof addressHash !== 'string' || addressHash.length !== 32) {
        console.error("Invalid addressHash:", addressHash);
        alert("خطا: اطلاعات آدرس برای پین کردن نامعتبر است.");
        return;
    }

    const card = starElement.closest('.outage-card');
    if (!card) {
        console.error("Could not find parent outage-card for star element.");
        return;
    }

    const isCurrentlyPinned = starElement.classList.contains('starred');
    const action = isCurrentlyPinned ? 'unpin' : 'pin';
    const originalChar = starElement.querySelector('.star-char').textContent;

    starElement.querySelector('.star-char').textContent = '⏳';
    starElement.style.pointerEvents = 'none';

    const ajaxUrl = (typeof ajaxToggleGuestPinUrl !== 'undefined' && ajaxToggleGuestPinUrl)
        ? ajaxToggleGuestPinUrl
        : 'ajax_toggle_guest_pin.php';

    fetch(ajaxUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `address_hash=${encodeURIComponent(addressHash)}&action=${action}&guest_id=${encodeURIComponent(currentGuestIdentifier)}`
    })
    .then(response => {
        starElement.style.pointerEvents = 'auto';
        if (!response.ok) {
            return response.text().then(text => {
                throw new Error(`خطای سرور (${response.status}): ${text || response.statusText}`);
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            if (data.is_pinned) {
                starElement.querySelector('.star-char').textContent = '★';
                starElement.classList.add('starred');
                starElement.classList.remove('not-starred');
                starElement.querySelector('.star-label').textContent = 'پین شده';
                card.classList.add('user-starred-row');
            } else {
                starElement.querySelector('.star-char').textContent = '☆';
                starElement.classList.remove('starred');
                starElement.classList.add('not-starred');
                starElement.querySelector('.star-label').textContent = 'ذخیره';
                card.classList.remove('user-starred-row');
            }
            sortTableClientSideInitial();
        } else {
            starElement.querySelector('.star-char').textContent = originalChar;
            alert('خطا در عملیات پین کردن: ' + (data.message || 'ناموفق بود.'));
        }
    })
    .catch(error => {
        starElement.style.pointerEvents = 'auto';
        starElement.querySelector('.star-char').textContent = originalChar;
        console.error('Error toggling guest pin:', error);
        alert('خطا در ارتباط با سرور. اتصال اینترنت را بررسی کنید.');
    });
}

function sortTableClientSideInitial() {
    const cardList = document.querySelector(".outage-card-list");
    if (!cardList) return;
    const cards = Array.from(cardList.children);

    cards.sort((a, b) => {
        const aIsGuestPinned = a.classList.contains('user-starred-row');
        const bIsGuestPinned = b.classList.contains('user-starred-row');
        const aIsPhpPinned = a.classList.contains('php-pinned-row');
        const bIsPhpPinned = b.classList.contains('php-pinned-row');

        if (aIsGuestPinned && !bIsGuestPinned) return -1;
        if (!aIsGuestPinned && bIsGuestPinned) return 1;

        if (aIsPhpPinned && !bIsPhpPinned) return -1;
        if (!aIsPhpPinned && bIsPhpPinned) return 1;

        return 0;
    });

    cards.forEach(card => cardList.appendChild(card));
}
// دکمه بازگشت به بالا
let scrollTopButton;

function scrollFunction() {
    if (!scrollTopButton) return;
    if (document.body.scrollTop > 100 || document.documentElement.scrollTop > 100) {
        scrollTopButton.style.display = "block";
    } else {
        scrollTopButton.style.display = "none";
    }
}

function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

window.addEventListener('DOMContentLoaded', () => {
    // مقداردهی به دکمه بازگشت به بالا
    scrollTopButton = document.getElementById("scrollTopBtn");
    
    if (scrollTopButton) {
        scrollTopButton.addEventListener("click", scrollToTop);
        scrollTopButton.style.display = "none"; // در شروع مخفی باشد
    }

    // اگر جدول دارید
    if (typeof sortTableClientSideInitial === 'function') {
        sortTableClientSideInitial();
    }

    const searchInput = document.getElementById("searchInput");
    if (searchInput) {
        searchInput.addEventListener("input", filterTable);
    }

    // وضعیت اولیه دکمه بررسی شود
    scrollFunction();
});

// اجرا هنگام اسکرول
window.addEventListener("scroll", scrollFunction);
