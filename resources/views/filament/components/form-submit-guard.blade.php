{{--
    Owner 2026-11-10: "بدخل الطلب كامل وادوس إنشاء ميعملش أي أكشن ولا يجيب إيرور".
    Filament blocks a form submit without a word while it thinks a file is still uploading
    (isProcessing). A photo that failed or was removed mid-upload can leave that flag on for
    good, so the button did nothing. On submit: if no file is really uploading, the flag is
    cleared and the form goes; if one is, the staff member is told to wait for it.
    A submit that comes back with errors in a hidden or collapsed spot gets a notice too.
--}}
<script>
    (() => {
        const notify = (title, body) => window.FilamentNotification
            ? new window.FilamentNotification().title(title).body(body || '').warning().duration(8000).send()
            : alert(title + (body ? '\n' + body : ''))

        const uploading = (form) => [...form.querySelectorAll('.filepond--item')].some((item) => {
            const state = item.getAttribute('data-filepond-item-state') || ''

            return /processing|busy|loading/.test(state) && ! /complete|error|invalid/.test(state)
        })

        document.addEventListener('submit', (event) => {
            const form = event.target

            if (! (form instanceof HTMLFormElement) || ! form.classList.contains('fi-form') || ! window.Alpine) return

            const data = window.Alpine.$data(form)

            if (! data || ! data.isProcessing) return

            if (uploading(form)) {
                notify('استنى الصور تخلص رفع', 'فيه صورة لسه بتترفع. أول ما تخلص دوس إنشاء تاني.')

                return
            }

            // nothing is uploading: the flag is stale - let the form go (and free the button)
            data.isProcessing = false
            form.dispatchEvent(new CustomEvent('form-processing-finished'))
        }, true)

        // Owner 2026-11-24: the errors notice used to go out on every live update of the form (a
        // select, a typed field) while an error was still on it, so they piled up over the page and
        // blocked it. Now: only when the form is saved, and one at a time (the old one closes).
        let lastNoticeId = null
        const notifyOnce = (title, body) => {
            if (lastNoticeId) window.dispatchEvent(new CustomEvent('close-notification', { detail: { id: lastNoticeId } }))
            if (! window.FilamentNotification) return notify(title, body)
            // (the JS Notification's id() replaces itself with the value, so its own uuid is read instead)
            const notice = new window.FilamentNotification()
            lastNoticeId = notice.id
            notice.title(title).body(body || '').warning().duration(6000).send()
        }

        document.addEventListener('livewire:init', () => {
            window.Livewire.hook('commit', ({ commit, succeed }) => {
                const saving = (commit?.calls || []).some((c) => /^(save|create|createAnother)$/.test(c.method))

                if (! saving) return

                succeed(() => setTimeout(() => {
                    const errors = [...document.querySelectorAll('.fi-form [data-validation-error]')]

                    if (! errors.length) return

                    const labels = errors.map((e) => e.closest('[data-field-wrapper]')?.querySelector('label')?.innerText?.replace('*', '').trim())
                        .filter(Boolean)

                    notifyOnce('الطلب ما اتسجلش - فيه بيانات ناقصة أو غلط', [...new Set(labels)].slice(0, 6).join('، '))
                }, 150))
            })
        })
    })()
</script>
