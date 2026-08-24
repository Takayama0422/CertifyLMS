/**
 * TopBar 通知ポップオーバー(S-A-05)。
 * 支給済みの HTML(resources/views/layouts/_partials/topbar.blade.php /
 * resources/views/notifications/_partials/notification-popover.blade.php)が持つ data-* を目印に DOM を探し、
 * `/api/v1/notifications` 系の JSON API(Sanctum Cookie 認証 + CSRF)を fetch して動的に描画する。
 *
 * 対応する data-* とその役割(★着手前の全文検索で洗い出したもの):
 *   data-notification-popover-root            ベル + パネルのラッパ(開閉状態の外側クリック判定に使用)
 *   data-notification-popover-trigger         ベルボタン(クリックで開閉)
 *   data-notification-popover-badge           ベル右上の未読数バッジ(0 件で hidden, 99 件超で 99+)
 *   data-notification-popover-panel           パネル本体(id="notification-popover-panel")
 *   data-notification-popover-tab             タブボタン(値は "all" / "unread")
 *   data-notification-popover-unread-count    「未読」タブ内の件数バッジ
 *   data-notification-popover-mark-all        全件既読ボタン
 *   data-notification-popover-list            本文のスクロール領域(再取得・タブ切替で先頭へ戻す)
 *   data-notification-popover-loading         読み込み中スピナー
 *   data-notification-popover-empty           空状態メッセージ
 *   data-notification-popover-items           行 <ul>(JS が行を append する)
 *   data-notification-popover-row-template    行 <template>(1 件ごとに clone する)
 *   data-notification-popover-row             行の <a>(クリックで既読化 + 遷移)
 *   data-notification-popover-row-dot         未読の目印(丸ドット)
 *   data-notification-popover-row-title       行タイトル
 *   data-notification-popover-row-message     行プレビュー本文
 *   data-notification-popover-row-time        経過時間
 *
 * 支給 CSS の要注意点: 行の未読背景強調は Tailwind の `aria-[data-unread=true]:bg-primary-50/30` で
 * 定義されており、コンパイル後のセレクタは `[aria-data-unread="true"]` になる(`npm run build` 後の
 * public/build/assets/app-*.css を実測して確認)。素直に `data-unread` 属性を立てても効かないため、
 * JS 側は `aria-data-unread="true"` を付与する。
 *
 * ロールによる出し分け(要件シート S4: ポップオーバー表示は 受講生○ / コーチ○ / 管理者×、拒否ステータスなし):
 * 支給 Blade はロールで出し分けておらず、DOM 側にロールを示す目印も存在しない(Blade へ `<meta>` や
 * `data-*` を足すのは支給画面の変更にあたるため不可)。判定は API 側が `popover_visible` として返し
 * (App\Http\Controllers\Api\NotificationController::index)、JS はそれが false のときポップオーバーを
 * 開かない。ベル自体は支給画面のまま残す(サーバ側で算出した未読バッジの初期値はそのまま表示される)。
 *
 * 初回フェッチはページ表示時ではなくベルの初回クリック時に行う(全ロールの全ページ表示で API を
 * 叩かないため)。したがって判定が確定するまでパネルは開かず、確定後に初めて開く。
 */

import { getJson, postJson } from '../utils/fetch-json';

const BADGE_OVERFLOW_THRESHOLD = 99;

function formatBadgeCount(count) {
    return count > BADGE_OVERFLOW_THRESHOLD ? '99+' : String(count);
}

export function initNotificationPopover() {
    const root = document.querySelector('[data-notification-popover-root]');

    if (!root) return;

    const trigger = root.querySelector('[data-notification-popover-trigger]');
    const panel = root.querySelector('[data-notification-popover-panel]');
    const badge = root.querySelector('[data-notification-popover-badge]');
    const tabs = Array.from(root.querySelectorAll('[data-notification-popover-tab]'));
    const unreadCountEl = root.querySelector('[data-notification-popover-unread-count]');
    const markAllButton = root.querySelector('[data-notification-popover-mark-all]');
    const listEl = root.querySelector('[data-notification-popover-list]');
    const loadingEl = root.querySelector('[data-notification-popover-loading]');
    const emptyEl = root.querySelector('[data-notification-popover-empty]');
    const itemsEl = root.querySelector('[data-notification-popover-items]');
    const rowTemplate = root.querySelector('[data-notification-popover-row-template]');

    if (!trigger || !panel || !itemsEl || !rowTemplate) return;

    let notifications = [];
    let unreadCount = 0;
    let activeTab = 'all';
    let isOpen = false;
    let isBusy = false;
    // 管理者かどうかは初回の /api/v1/notifications 応答が届くまで判定できない(支給 DOM にロールの
    // 目印がないため)。null = 未判定、false = 管理者(開かない)、true = 受講生 / コーチ。
    let popoverVisible = null;
    let csrfCookieRequest = null;

    /**
     * Sanctum SPA Cookie 認証の素地(XSRF-TOKEN cookie の発行)。
     * 同一オリジン実装では支給の meta[name="csrf-token"] 経由の X-CSRF-TOKEN(utils/fetch-json.js)だけで
     * 検証は成立するが、要件シート S6 が明記する経路を状態変更前に一度だけ通し、別オリジン展開の素地を確保する。
     */
    function ensureCsrfCookie() {
        csrfCookieRequest ??= getJson('/sanctum/csrf-cookie').catch(() => {});

        return csrfCookieRequest;
    }

    function applyResponse(response) {
        notifications = response.data ?? [];
        unreadCount = response.unread_count ?? 0;
        updateBadges();
        renderList();
    }

    function updateBadges() {
        const label = formatBadgeCount(unreadCount);

        if (badge) {
            badge.textContent = label;
            badge.classList.toggle('hidden', unreadCount <= 0);
        }

        if (unreadCountEl) {
            unreadCountEl.textContent = label;
        }

        trigger.setAttribute('aria-label', `通知 (${unreadCount} 件未読)`);
    }

    function buildRow(notification) {
        const fragment = rowTemplate.content.cloneNode(true);
        const row = fragment.querySelector('[data-notification-popover-row]');
        const dot = fragment.querySelector('[data-notification-popover-row-dot]');
        const titleEl = fragment.querySelector('[data-notification-popover-row-title]');
        const messageEl = fragment.querySelector('[data-notification-popover-row-message]');
        const timeEl = fragment.querySelector('[data-notification-popover-row-time]');
        const isUnread = notification.read_at === null;

        if (row) {
            row.href = notification.url ?? '#';
            row.dataset.notificationId = notification.id;

            // 支給 CSS の未読強調セレクタは [aria-data-unread="true"](上部コメント参照)。
            if (isUnread) {
                row.setAttribute('aria-data-unread', 'true');
            } else {
                row.removeAttribute('aria-data-unread');
            }
        }

        // 既読行はドットの場所を保ったまま目印だけ消す(行の高さを揃えるため display は落とさない)。
        dot?.classList.toggle('opacity-0', !isUnread);

        if (titleEl) titleEl.textContent = notification.title ?? '通知';

        if (messageEl) {
            messageEl.textContent = notification.message ?? '';
            messageEl.classList.toggle('hidden', !notification.message);
        }

        if (timeEl) timeEl.textContent = notification.created_at_human ?? '';

        return fragment;
    }

    function renderList() {
        const filtered = activeTab === 'unread'
            ? notifications.filter((notification) => notification.read_at === null)
            : notifications;

        itemsEl.innerHTML = '';

        if (filtered.length === 0) {
            emptyEl?.classList.remove('hidden');
            itemsEl.classList.add('hidden');

            return;
        }

        emptyEl?.classList.add('hidden');
        itemsEl.classList.remove('hidden');

        filtered.forEach((notification) => itemsEl.appendChild(buildRow(notification)));

        // 描画のたびに内容が入れ替わるため、スクロール位置は先頭へ戻す。
        if (listEl) listEl.scrollTop = 0;
    }

    function setLoading(loading) {
        isBusy = loading;
        loadingEl?.classList.toggle('hidden', !loading);

        if (loading) {
            emptyEl?.classList.add('hidden');
            itemsEl.classList.add('hidden');
        }
    }

    /** ベル初回クリック時のみ走る。表示可否(ロール)判定を兼ねる。 */
    async function resolveVisibility() {
        try {
            const response = await getJson('/api/v1/notifications');

            popoverVisible = response.popover_visible !== false;

            if (popoverVisible) {
                applyResponse(response);
            }
        } catch (error) {
            // 判定できなかった場合は安全側(恒久的に無効)へ倒さず、通常どおり操作可能にする
            // (通信断でベルが永続的に死ぬより望ましい。中身は開いたときに再取得される)。
            popoverVisible = true;
        }

        return popoverVisible;
    }

    async function reload() {
        setLoading(true);

        try {
            applyResponse(await getJson('/api/v1/notifications'));
        } catch (error) {
            notifications = [];
            renderList();
        } finally {
            setLoading(false);
        }
    }

    function openPanel() {
        isOpen = true;
        panel.style.display = 'flex';
        panel.classList.remove('hidden');
        // 支給 CSS の opacity-0 / -translate-y-1 を外して開くトランジションを走らせる。
        requestAnimationFrame(() => panel.classList.remove('opacity-0', '-translate-y-1'));
        trigger.setAttribute('aria-expanded', 'true');
    }

    function closePanel() {
        isOpen = false;
        panel.classList.add('opacity-0', '-translate-y-1', 'hidden');
        panel.style.display = 'none';
        trigger.setAttribute('aria-expanded', 'false');
    }

    function selectTab(tabName) {
        if (tabName !== 'all' && tabName !== 'unread') return;

        activeTab = tabName;

        tabs.forEach((tabButton) => {
            tabButton.setAttribute(
                'aria-selected',
                tabButton.dataset.notificationPopoverTab === tabName ? 'true' : 'false',
            );
        });

        renderList();
    }

    async function toggle() {
        if (isOpen) {
            closePanel();

            return;
        }

        if (popoverVisible === null) {
            // 初回クリック: 判定と初期データ取得が済んでから開く(管理者にパネルを一瞬も見せない)。
            const visible = await resolveVisibility();

            if (!visible) return;

            openPanel();

            return;
        }

        // 管理者は対象外。ポップオーバーを開かない(要件シート S4、拒否ステータスは持たない)。
        if (popoverVisible === false) return;

        openPanel();
        await reload();
    }

    trigger.addEventListener('click', (event) => {
        event.stopPropagation();
        void toggle();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen) {
            closePanel();
            trigger.focus();
        }
    });

    document.addEventListener('click', (event) => {
        if (isOpen && !root.contains(event.target)) {
            closePanel();
        }
    });

    tabs.forEach((tabButton) => {
        tabButton.addEventListener('click', () => selectTab(tabButton.dataset.notificationPopoverTab));
    });

    markAllButton?.addEventListener('click', async () => {
        if (isBusy) return;

        isBusy = true;

        try {
            await ensureCsrfCookie();

            const response = await postJson('/api/v1/notifications/read-all');
            const readAt = new Date().toISOString();

            notifications = notifications.map((notification) => ({
                ...notification,
                read_at: notification.read_at ?? readAt,
            }));
            unreadCount = response.unread_count ?? 0;
            updateBadges();
            renderList();
        } catch (error) {
            // 失敗時は UI 状態を変えない(次に開いたときに再取得される)。
        } finally {
            isBusy = false;
        }
    });

    itemsEl.addEventListener('click', (event) => {
        const row = event.target.closest?.('[data-notification-popover-row]');

        if (!row) return;

        event.preventDefault();

        const notificationId = row.dataset.notificationId;
        const target = notifications.find((notification) => notification.id === notificationId);
        const destination = row.href;
        const needsMarking = target !== undefined && target.read_at === null;

        // 既読化してから遷移する(既読の行は API を叩かずそのまま遷移)。
        const markRead = needsMarking
            ? ensureCsrfCookie()
                .then(() => postJson(`/api/v1/notifications/${notificationId}/read`))
                .then((response) => {
                    unreadCount = response.unread_count ?? Math.max(0, unreadCount - 1);
                    updateBadges();
                })
                .catch(() => {})
            : Promise.resolve();

        markRead.finally(() => {
            window.location.href = destination;
        });
    });
}
