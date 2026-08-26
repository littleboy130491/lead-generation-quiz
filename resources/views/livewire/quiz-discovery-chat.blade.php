<div class="quiz-chat" aria-label="AI quiz interview">
    <style>
        .quiz-chat { --qc-ink:var(--quiz-chat-ink,#172033); --qc-muted:var(--quiz-chat-muted,#697386); --qc-line:var(--quiz-chat-line,#e6e9ef); --qc-surface:var(--quiz-chat-surface,#f7f8fa); --qc-canvas:var(--quiz-chat-canvas,#ffffff); --qc-brand:var(--quiz-chat-primary-600,#d97706); --qc-brand-dark:var(--quiz-chat-primary-700,#b45309); --qc-brand-soft:var(--quiz-chat-primary-50,#fffbeb); color:var(--qc-ink); font-family:inherit; }
        .quiz-chat *, .quiz-chat *::before, .quiz-chat *::after { box-sizing:border-box; }
        .quiz-chat__shell { height:min(72dvh,780px); min-height:min(560px,calc(100dvh - 9rem)); max-height:calc(100dvh - 9rem); display:grid; grid-template-rows:auto minmax(0,1fr) auto; overflow:hidden; border:1px solid var(--qc-line); border-radius:24px; background:var(--qc-canvas); box-shadow:0 16px 50px rgb(23 32 51 / .12); }
        .quiz-chat__header { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:18px 22px; border-bottom:1px solid var(--qc-line); background:var(--qc-canvas); }
        .quiz-chat__identity { display:flex; align-items:center; gap:11px; min-width:0; }
        .quiz-chat__avatar { display:grid; place-items:center; width:36px; height:36px; flex:0 0 auto; border-radius:12px; background:var(--qc-ink); color:#fff; font-size:13px; font-weight:800; letter-spacing:.04em; }
        .quiz-chat__title { margin:0; font-size:15px; font-weight:750; line-height:1.2; }
        .quiz-chat__subtitle { margin:3px 0 0; color:var(--qc-muted); font-size:12px; }
        .quiz-chat__header-actions { display:flex; align-items:center; gap:4px; }
        .quiz-chat__review { border:0; background:transparent; color:var(--qc-brand); padding:8px; font:inherit; font-size:13px; font-weight:700; cursor:pointer; }
        .quiz-chat__review:hover { text-decoration:underline; }
        .quiz-chat__stream { min-height:0; overflow-y:auto; overscroll-behavior:contain; padding:28px clamp(18px,4vw,64px); background:var(--qc-surface); }
        .quiz-chat__welcome { max-width:580px; margin:10vh auto 0; text-align:center; }
        .quiz-chat__welcome h2 { margin:0; font-size:clamp(25px,3vw,34px); letter-spacing:-.035em; line-height:1.12; }
        .quiz-chat__welcome p { max-width:480px; margin:14px auto 0; color:var(--qc-muted); font-size:15px; line-height:1.6; }
        .quiz-chat__message { display:flex; margin:0 auto 18px; max-width:720px; }
        .quiz-chat__message--user { justify-content:flex-end; }
        .quiz-chat__bubble { max-width:min(82%,560px); padding:13px 16px; border-radius:18px; background:#fff; border:1px solid var(--qc-line); box-shadow:0 1px 1px rgba(23,32,51,.03); font-size:15px; line-height:1.55; white-space:pre-wrap; }
        .quiz-chat__message--user .quiz-chat__bubble { border-color:var(--qc-brand); background:var(--qc-brand); color:#fff; border-bottom-right-radius:5px; }
        .quiz-chat__message--assistant .quiz-chat__bubble { border-bottom-left-radius:5px; }
        .quiz-chat__sender { display:block; margin:0 0 5px; color:var(--qc-muted); font-size:10px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; }
        .quiz-chat__message--user .quiz-chat__sender { color:rgba(255,255,255,.7); }
        .quiz-chat__composer { display:flex; gap:10px; align-items:flex-end; padding:16px clamp(18px,4vw,64px); border-top:1px solid var(--qc-line); background:#fff; }
        .quiz-chat__textarea { width:100%; min-height:52px; max-height:132px; padding:14px 15px; border:1px solid #cfd5df; border-radius:15px; outline:none; resize:vertical; color:var(--qc-ink); background:#fff; font:inherit; font-size:15px; line-height:1.45; }
        .quiz-chat__textarea:focus { border-color:var(--qc-brand); box-shadow:0 0 0 3px rgb(var(--quiz-chat-primary-rgb, 217 119 6) / .18); }
        .quiz-chat__send { display:inline-flex; align-items:center; justify-content:center; flex:0 0 52px; width:52px; min-height:52px; padding:0; border:0; border-radius:50%; background:var(--qc-brand); color:#fff; cursor:pointer; font:inherit; }
        .quiz-chat__send svg { width:21px; height:21px; fill:currentColor; transform:translate(-1px,1px); }
        .quiz-chat__send:hover { background:var(--qc-brand-dark); transform:translateY(-1px); }
        .quiz-chat__send:disabled { cursor:not-allowed; opacity:.45; transform:none; }
        .quiz-chat__button-spinner { width:18px; height:18px; border:2px solid rgb(255 255 255 / .4); border-top-color:#fff; border-radius:999px; animation:quiz-chat-spin .65s linear infinite; }
        .quiz-chat__typing .quiz-chat__bubble { min-width:70px; }
        .quiz-chat__typing-dots { display:flex; gap:5px; align-items:center; height:18px; }
        .quiz-chat__typing-dots i { width:7px; height:7px; border-radius:50%; background:var(--qc-muted); animation:quiz-chat-bounce 1.05s ease-in-out infinite; }
        .quiz-chat__typing-dots i:nth-child(2) { animation-delay:.15s; }.quiz-chat__typing-dots i:nth-child(3) { animation-delay:.3s; }
        @keyframes quiz-chat-spin { to { transform:rotate(360deg); } } @keyframes quiz-chat-bounce { 0%,60%,100% { transform:translateY(2px); opacity:.4; } 30% { transform:translateY(-3px); opacity:1; } }
        .quiz-chat__error { max-width:720px; margin:8px auto 0; color:#c72929; font-size:13px; }
        .quiz-chat__brief { max-width:720px; margin:0 auto; padding:28px; border-radius:20px; background:#fff; border:1px solid var(--qc-line); }
        .quiz-chat__brief h2 { margin:0; font-size:22px; letter-spacing:-.02em; }.quiz-chat__brief>p{margin:7px 0 24px;color:var(--qc-muted);font-size:14px;line-height:1.5;}
        .quiz-chat__fields { display:grid; grid-template-columns:1fr 1fr; gap:16px; }.quiz-chat__field--wide{grid-column:1/-1;}.quiz-chat__field label{display:block;margin-bottom:6px;font-size:13px;font-weight:700;}.quiz-chat__field input,.quiz-chat__field textarea{width:100%;padding:11px 12px;border:1px solid #cfd5df;border-radius:10px;background:#fff;color:var(--qc-ink);font:inherit;font-size:14px;}.quiz-chat__field textarea{resize:vertical;}.quiz-chat__actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:24px;}.quiz-chat__action-button{min-height:44px;padding:0 16px;border:0;border-radius:12px;background:var(--qc-brand);color:#fff;font:inherit;font-size:14px;font-weight:700;cursor:pointer;}.quiz-chat__action-button:hover{background:var(--qc-brand-dark);}.quiz-chat__action-button:disabled{opacity:.55;cursor:not-allowed;}.quiz-chat__save{border:1px solid #cfd5df;background:#fff;color:var(--qc-ink);}
        @media (max-width:640px){.quiz-chat__shell{height:calc(100dvh - 7rem);min-height:0;max-height:calc(100dvh - 7rem);border-radius:16px}.quiz-chat__header{padding:14px 16px}.quiz-chat__stream{padding:18px 14px}.quiz-chat__composer{padding:12px 14px}.quiz-chat__send{min-width:auto;padding:0 14px}.quiz-chat__bubble{max-width:90%;font-size:14px}.quiz-chat__welcome{margin-top:6vh}.quiz-chat__fields{grid-template-columns:1fr}.quiz-chat__field--wide{grid-column:auto}.quiz-chat__brief{padding:20px}}
    </style>

    <div
        wire:key="quiz-chat-shell-{{ $sessionId ?? 'new' }}"
        class="quiz-chat__shell"
        x-data="{
            draft: '',
            pending: null,
            sending: false,
            scrollToLatest() {
                this.$nextTick(() => {
                    this.$refs.stream.scrollTop = this.$refs.stream.scrollHeight
                })
            },
            async send() {
                const message = this.draft.trim()
                if (! message || this.sending) return

                this.pending = message
                this.draft = ''
                this.sending = true
                this.scrollToLatest()

                try {
                    await $wire.{{ $sessionId === null ? 'startDiscovery' : 'sendReply' }}(message)
                } catch (error) {
                    this.draft = message
                } finally {
                    this.pending = null
                    this.sending = false
                }
            },
        }"
    >
        <header class="quiz-chat__header">
            <div class="quiz-chat__identity">
                <div class="quiz-chat__avatar">AI</div>
                <div><p class="quiz-chat__title">Quiz assistant</p><p class="quiz-chat__subtitle">A focused conversation before creating a draft</p></div>
            </div>
            @if ($sessionId !== null)
                <div class="quiz-chat__header-actions">
                    <button class="quiz-chat__review" type="button" wire:click="startNewInterview">New interview</button>
                    <button class="quiz-chat__review" type="button" wire:click="$toggle('showBrief')">{{ $showBrief ? 'Back to chat' : 'Review brief' }}</button>
                </div>
            @endif
        </header>

        <main class="quiz-chat__stream" x-ref="stream" aria-live="polite">
            @if ($showBrief)
                <section class="quiz-chat__brief">
                    <h2>Review the quiz brief</h2>
                    <p>Edit these details before creating the draft. The conversation itself is never passed directly into generation.</p>
                    <div class="quiz-chat__brief-editor">
                        <div class="quiz-chat__fields">
                            <div class="quiz-chat__field quiz-chat__field--wide"><label for="brief-context">Business context</label><textarea id="brief-context" wire:model="brief.business_context" rows="3"></textarea></div>
                            <div class="quiz-chat__field"><label for="brief-audience">Target audience</label><input id="brief-audience" wire:model="brief.target_audience" /></div>
                            <div class="quiz-chat__field"><label for="brief-objective">Objective</label><input id="brief-objective" wire:model="brief.objective" /></div>
                            <div class="quiz-chat__field quiz-chat__field--wide"><label for="brief-insight">Desired insight</label><input id="brief-insight" wire:model="brief.desired_insight" /></div>
                            <div class="quiz-chat__field"><label for="brief-count">Number of questions</label><input id="brief-count" type="number" min="1" max="30" wire:model="brief.question_count" /></div>
                            <div class="quiz-chat__field"><label for="brief-tone">Tone</label><input id="brief-tone" wire:model="brief.tone" /></div>
                        </div>
                        <div class="quiz-chat__actions"><button class="quiz-chat__action-button quiz-chat__save" type="button" wire:click="saveBrief" wire:loading.attr="disabled">Save changes</button><button class="quiz-chat__action-button" type="button" wire:click="generateDraft" wire:loading.attr="disabled">Generate draft</button></div>
                    </div>
                </section>
            @elseif ($sessionId === null)
                <div class="quiz-chat__welcome"><h2>What quiz do you want to create?</h2><p>Tell me the rough idea. I will ask only what is needed to make a useful lead-generation quiz.</p></div>
            @else
                @foreach ($this->session()?->messages ?? [] as $message)
                    <article class="quiz-chat__message quiz-chat__message--{{ $message->role === 'assistant' ? 'assistant' : 'user' }}"><div class="quiz-chat__bubble">@if ($message->role === 'assistant')<span class="quiz-chat__sender">Quiz assistant</span>@endif{{ $message->content }}</div></article>
                @endforeach
            @endif
            <template x-if="pending">
                <article class="quiz-chat__message quiz-chat__message--user quiz-chat__message--pending"><div class="quiz-chat__bubble"><span x-text="pending"></span></div></article>
            </template>
            <template x-if="sending">
                <article class="quiz-chat__message quiz-chat__message--assistant quiz-chat__typing" aria-label="Quiz assistant is typing"><div class="quiz-chat__bubble"><span class="quiz-chat__typing-dots"><i></i><i></i><i></i></span></div></article>
            </template>
        </main>

        @if (! $showBrief)
            <div
                wire:key="quiz-chat-composer-{{ $sessionId ?? 'new' }}"
                class="quiz-chat__composer"
                role="group"
                aria-label="{{ $sessionId === null ? 'Start chat' : 'Send answer' }}"
            >
                <textarea
                    id="quiz-chat-message"
                    class="quiz-chat__textarea"
                    x-model="draft"
                    x-on:keydown.enter.meta.prevent="send()"
                    x-on:keydown.enter.ctrl.prevent="send()"
                    rows="2"
                    aria-label="{{ $sessionId === null ? 'Describe the quiz you want to create' : 'Write your answer' }}"
                    placeholder="{{ $sessionId === null ? 'Describe the quiz you want to create…' : 'Write a reply…' }}"
                ></textarea>
                <button class="quiz-chat__send" type="button" x-on:click="send()" x-bind:disabled="! draft.trim() || sending" aria-label="{{ $sessionId === null ? 'Start chat' : 'Send message' }}" title="{{ $sessionId === null ? 'Start chat' : 'Send message' }}">
                    <svg x-show="! sending" viewBox="0 0 24 24" aria-hidden="true"><path d="M21.5 2.7 3 10.1c-.9.4-.9 1.7.1 2l7.1 2.3 2.3 7.1c.3 1 1.6 1 2 .1l7.4-18.5c.3-.7-.4-1.4-1.1-1.1ZM11.2 13.1l-1.4 5-1.2-3.7-3.7-1.2 12.9-5.1-6.7 5Z" /></svg>
                    <span class="quiz-chat__button-spinner" x-show="sending" aria-hidden="true"></span>
                </button>
            </div>
            @error($sessionId === null ? 'opening' : 'reply') <p class="quiz-chat__error">{{ $message }}</p> @enderror
        @endif
    </div>

    @error('opening') <p class="quiz-chat__error">{{ $message }}</p> @enderror
    @error('reply') <p class="quiz-chat__error">{{ $message }}</p> @enderror
</div>
