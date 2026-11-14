import './bootstrap';
import { Modal } from 'bootstrap';
import 'bootstrap';
import './address-selector';

document.querySelectorAll('[data-market-price-range]').forEach((range) => {
    const output = document.querySelector(`[data-market-price-output][for="${range.id}"]`);
    if (!output) return;

    const updatePriceOutput = () => {
        const value = Number(range.value);
        output.value = `৳${value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        output.textContent = output.value;
    };

    range.addEventListener('input', updatePriceOutput);
    updatePriceOutput();
});

document.querySelectorAll('[data-product-photo-input]').forEach((input) => {
    const preview = input.parentElement.querySelector('[data-product-photo-previews]');
    input.addEventListener('change', () => {
        preview.replaceChildren();
        const files = [...input.files];
        files.forEach((file, index) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'position-relative';
            const image = document.createElement('img');
            image.alt = file.name;
            image.width = 100;
            image.height = 75;
            image.style.objectFit = 'cover';
            image.className = 'rounded border';
            image.src = URL.createObjectURL(file);
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-danger position-absolute top-0 end-0';
            remove.textContent = '×';
            remove.setAttribute('aria-label', `Remove ${file.name}`);
            remove.addEventListener('click', () => {
                const transfer = new DataTransfer();
                files.filter((_, fileIndex) => fileIndex !== index).forEach((selected) => transfer.items.add(selected));
                input.files = transfer.files;
                input.dispatchEvent(new Event('change'));
            });
            wrapper.append(image, remove);
            preview.append(wrapper);
        });
    });
});

document.querySelectorAll('[data-fallback-image]').forEach((image) => {
    const placeholder = image.nextElementSibling;

    if (!placeholder?.matches('[data-image-placeholder]')) {
        return;
    }

    const showPlaceholder = () => {
        image.hidden = true;
        placeholder.hidden = false;
    };

    image.addEventListener('error', showPlaceholder, { once: true });

    if (image.complete && image.naturalWidth === 0) {
        showPlaceholder();
    }
});

const chatPanel = document.querySelector('[data-kh-chat-panel]');

if (chatPanel) {
    const chatToggles = document.querySelectorAll('[data-kh-chat-toggle]');
    const chatClose = chatPanel.querySelector('[data-kh-chat-close]');
    const chatForm = chatPanel.querySelector('[data-kh-chat-form]');
    const chatInput = chatPanel.querySelector('[data-kh-chat-input]');
    const chatSubmit = chatPanel.querySelector('[data-kh-chat-submit]');
    const chatMessages = chatPanel.querySelector('[data-kh-chat-messages]');
    const loadingIndicator = chatPanel.querySelector('[data-kh-chat-loading]');

    // ---------- ভয়েস (Text-to-Speech) ----------
    const canSpeak = 'speechSynthesis' in window;
    let voiceOn = true;
    try {
        voiceOn = localStorage.getItem('kh-voice') !== 'off';
    } catch (e) {}

    const pickBengaliVoice = () => {
        const voices = window.speechSynthesis.getVoices();
        const bengaliVoices = voices.filter((v) => v.lang.toLowerCase().startsWith('bn'));

        // মেয়ের কণ্ঠ আগে খুঁজবে (Edge: Nabanita, Tanishaa; অন্য নামেও চেষ্টা করবে)
        const femaleVoice = bengaliVoices.find((v) => /nabanita|tanishaa|female|woman|মেয়ে/i.test(v.name));
        if (femaleVoice) return femaleVoice;

        // ছেলের কণ্ঠ (Pradeep ইত্যাদি) বাদ দিয়ে বাকিগুলো
        const notMale = bengaliVoices.find((v) => !/pradeep|male|man|ছেলে/i.test(v.name) || /female/i.test(v.name));
        if (notMale) return notMale;

        return bengaliVoices[0] || null;
    };

    const speakReply = (text) => {
        if (!canSpeak || !voiceOn || !text) return;
        window.speechSynthesis.cancel();
        const speech = new SpeechSynthesisUtterance(text);
        speech.lang = 'bn-BD';
        const voice = pickBengaliVoice();
        if (voice) speech.voice = voice;
        speech.rate = 0.95;
        speech.pitch = 1.15; // একটু চিকন সুর, মেয়েলি শোনাতে সাহায্য করে
        window.speechSynthesis.speak(speech);
    };

    const stopSpeaking = () => {
        if (canSpeak) window.speechSynthesis.cancel();
    };

    if (canSpeak) {
        // কিছু ব্রাউজারে ভয়েসের তালিকা দেরিতে লোড হয়
        window.speechSynthesis.getVoices();
        window.speechSynthesis.addEventListener('voiceschanged', pickBengaliVoice);

        // হেডারে 🔊 বাটন যোগ করা
        const chatHeader = chatPanel.querySelector('.kh-chat-header');
        const voiceBtn = document.createElement('button');
        voiceBtn.type = 'button';
        voiceBtn.className = 'btn btn-sm btn-outline-light rounded-circle p-1 ms-auto me-2';
        voiceBtn.style.cssText = 'width:28px;height:28px;line-height:1;';
        const refreshVoiceBtn = () => {
            voiceBtn.textContent = voiceOn ? '🔊' : '🔇';
            voiceBtn.title = voiceOn ? 'ভয়েস চালু আছে (বন্ধ করতে চাপুন)' : 'ভয়েস বন্ধ আছে (চালু করতে চাপুন)';
            voiceBtn.setAttribute('aria-label', voiceBtn.title);
        };
        refreshVoiceBtn();
        voiceBtn.addEventListener('click', () => {
            voiceOn = !voiceOn;
            try {
                localStorage.setItem('kh-voice', voiceOn ? 'on' : 'off');
            } catch (e) {}
            if (!voiceOn) stopSpeaking();
            refreshVoiceBtn();
        });
        const closeBtn = chatHeader?.querySelector('[data-kh-chat-close]');
        if (chatHeader && closeBtn) chatHeader.insertBefore(voiceBtn, closeBtn);
    }
    // ---------- ভয়েস শেষ ----------

    const addMessage = (message, kind = 'assistant') => {
        const item = document.createElement('div');
        item.className = `kh-chat-message kh-chat-message-${kind}`;
        item.textContent = message;
        chatMessages.append(item);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        return item;
    };

    const setChatOpen = (isOpen) => {
        chatPanel.hidden = !isOpen;
        chatToggles.forEach((toggle) => toggle.setAttribute('aria-expanded', String(isOpen)));

        if (isOpen) {
            chatInput.focus();
        } else {
            stopSpeaking();
            chatToggles[0]?.focus();
        }
    };

    chatToggles.forEach((toggle) => toggle.addEventListener('click', () => setChatOpen(chatPanel.hidden)));
    chatClose.addEventListener('click', () => setChatOpen(false));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !chatPanel.hidden) {
            setChatOpen(false);
        }
    });

    const chatEndpoint = chatPanel.dataset.khChatEndpoint;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    let chatSending = false;

    const sendChatMessage = async () => {
        const message = chatInput.value.trim();

        if (!message || chatSending || !chatEndpoint) {
            return;
        }

        stopSpeaking();
        chatSending = true;
        addMessage(message, 'user');
        chatInput.value = '';
        chatSubmit.disabled = true;
        loadingIndicator.hidden = false;

        try {
            const response = await fetch(chatEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ message }),
            });

            const data = await response.json().catch(() => ({}));

            if (response.status === 429) {
                addMessage('অনেকগুলো বার্তা পাঠানো হয়েছে। এক মিনিট পরে আবার চেষ্টা করুন।', 'assistant kh-chat-message-error');
            } else if (!response.ok) {
                addMessage(data.message || 'দুঃখিত, এখন উত্তর দেওয়া যাচ্ছে না। একটু পরে আবার চেষ্টা করুন।', 'assistant kh-chat-message-error');
            } else {
                const reply = data.reply || 'দুঃখিত, কোনো উত্তর পাওয়া যায়নি।';
                addMessage(reply);
                speakReply(reply);
            }
        } catch (error) {
            addMessage('ইন্টারনেট সংযোগে সমস্যা হচ্ছে। সংযোগ দেখে আবার চেষ্টা করুন।', 'assistant kh-chat-message-error');
        } finally {
            chatSending = false;
            chatSubmit.disabled = false;
            loadingIndicator.hidden = true;
            chatInput.focus();
        }
    };

    chatForm.addEventListener('submit', (event) => {
        event.preventDefault();
        sendChatMessage();
    });

    // Enter sends the message, Shift+Enter makes a new line.
    chatInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            sendChatMessage();
        }
    });

    // ---------- মাইক (Speech-to-Text): কথা বললে লেখা হবে ও নিজে থেকে পাঠাবে ----------
    const SpeechRecognitionAPI = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (SpeechRecognitionAPI) {
        const recognition = new SpeechRecognitionAPI();
        recognition.lang = 'bn-BD';
        recognition.interimResults = true;
        recognition.continuous = false;

        let listening = false;
        const defaultPlaceholder = chatInput.placeholder;

        const micBtn = document.createElement('button');
        micBtn.type = 'button';
        micBtn.className = 'btn btn-outline-success align-self-end';
        micBtn.textContent = '🎤';
        micBtn.title = 'মাইক চাপুন ও কথা বলুন';
        micBtn.setAttribute('aria-label', 'মাইক দিয়ে কথা বলুন');
        chatSubmit.before(micBtn);

        recognition.onstart = () => {
            listening = true;
            micBtn.textContent = '⏹️';
            micBtn.classList.add('active');
            chatInput.placeholder = 'শুনছি… এখন বলুন';
        };

        recognition.onresult = (event) => {
            let text = '';
            for (let i = 0; i < event.results.length; i++) {
                text += event.results[i][0].transcript;
            }
            chatInput.value = text;
        };

        recognition.onerror = (event) => {
            if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                addMessage('মাইক ব্যবহারের অনুমতি দেওয়া হয়নি। ঠিকানার পাশের 🔒 আইকনে চাপ দিয়ে মাইক "Allow" করুন।', 'assistant kh-chat-message-error');
            } else if (event.error === 'no-speech') {
                addMessage('কোনো কথা শোনা যায়নি। আবার মাইক চেপে বলুন।', 'assistant kh-chat-message-error');
            } else if (event.error === 'network') {
                addMessage('ইন্টারনেট সংযোগ নেই, তাই কথা বোঝা যাচ্ছে না।', 'assistant kh-chat-message-error');
            }
        };

        recognition.onend = () => {
            listening = false;
            micBtn.textContent = '🎤';
            micBtn.classList.remove('active');
            chatInput.placeholder = defaultPlaceholder;

            // কথা বলা শেষ হলে লেখা থাকলে নিজে থেকে পাঠিয়ে দেবে
            if (chatInput.value.trim()) {
                sendChatMessage();
            }
        };

        micBtn.addEventListener('click', () => {
            if (listening) {
                recognition.stop();
                return;
            }

            stopSpeaking();
            chatInput.value = '';

            try {
                recognition.start();
            } catch (e) {}
        });
    }
    // ---------- মাইক শেষ ----------

    chatSubmit.disabled = false;
}

const conversationMessages = document.querySelector('[data-chat-message-list]');

if (conversationMessages) {
    const endpoint = conversationMessages.dataset.messagesUrl;
    const currentUserId = Number(conversationMessages.dataset.currentUserId);
    let lastMessageId = Number(conversationMessages.dataset.lastMessageId || 0);
    let polling = false;
    const messageIds = new Set(
        [...conversationMessages.querySelectorAll('[data-message-id]')].map((item) => Number(item.dataset.messageId)),
    );
    const connectionStatus = document.querySelector('[data-chat-connection]');
    const imageModalElement = document.querySelector('#chatImageModal');
    const imageModal = imageModalElement ? Modal.getOrCreateInstance(imageModalElement) : null;
    const modalImage = imageModalElement?.querySelector('[data-chat-image-modal]');

    conversationMessages.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const link = target?.closest('[data-chat-image-open]');
        if (!link || !imageModal || !modalImage) return;

        event.preventDefault();
        modalImage.src = link.href;
        modalImage.alt = link.querySelector('img')?.alt || 'Chat image';
        imageModal.show();
    });

    imageModalElement?.addEventListener('hidden.bs.modal', () => {
        if (modalImage) modalImage.removeAttribute('src');
    });

    const appendMessage = (message) => {
        const messageId = Number(message.id);
        if (messageIds.has(messageId)) return;

        const wasAtBottom = conversationMessages.scrollHeight - conversationMessages.scrollTop - conversationMessages.clientHeight < 48;
        const emptyState = conversationMessages.querySelector('[data-chat-empty]');
        emptyState?.remove();

        const article = document.createElement('article');
        article.className = `mb-3 ${Number(message.sender_id) === currentUserId ? 'text-end' : ''}`;
        article.dataset.messageId = String(messageId);

        const timestamp = document.createElement('span');
        timestamp.className = 'small text-secondary';
        timestamp.textContent = `${message.sender_name} · ${message.sent_at}`;

        const bubble = document.createElement('div');
        bubble.className = 'd-inline-block text-start p-3 rounded bg-light';
        bubble.style.cssText = 'max-width:100%;overflow-wrap:anywhere';

        if (message.body) {
            const body = document.createElement('p');
            body.className = 'mb-0';
            body.textContent = message.body;
            bubble.append(body);
        }

        if (message.attachment_url) {
            let attachmentUrl;
            try {
                attachmentUrl = new URL(message.attachment_url, window.location.href);
            } catch {
                return;
            }

            if (attachmentUrl.origin !== window.location.origin) return;

            const link = document.createElement('a');
            link.href = attachmentUrl.href;
            link.dataset.chatImageOpen = '';
            link.setAttribute('aria-label', 'Open full image attachment');

            const image = document.createElement('img');
            image.src = attachmentUrl.href;
            image.alt = `Image sent by ${message.sender_name}`;
            image.loading = 'lazy';
            image.style.cssText = 'display:block;max-width:min(100%, 420px);max-height:420px;width:auto;height:auto;border-radius:.5rem;margin-top:.5rem';
            link.append(image);
            bubble.append(link);
        }

        article.append(timestamp, document.createElement('br'), bubble);
        conversationMessages.append(article);
        messageIds.add(messageId);
        lastMessageId = Math.max(lastMessageId, messageId);

        if (wasAtBottom) conversationMessages.scrollTop = conversationMessages.scrollHeight;
    };

    const pollMessages = async () => {
        if (polling || document.hidden) return;
        polling = true;

        try {
            const response = await fetch(`${endpoint}?after=${lastMessageId}`, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) throw new Error(`Message refresh failed (${response.status})`);
            const messages = await response.json();
            messages.forEach(appendMessage);
            if (connectionStatus) connectionStatus.textContent = 'Connected · checking for messages every 3 seconds';
        } catch {
            if (connectionStatus) connectionStatus.textContent = 'Connection issue · retrying shortly';
        } finally {
            polling = false;
            window.setTimeout(pollMessages, document.hidden ? 5000 : 3000);
        }
    };

    pollMessages();
}