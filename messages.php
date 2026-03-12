<?php
// messages.php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!is_logged_in()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header("Location: login.php");
    die();
}

$current_user_id = (int)$_SESSION['user_id'];
$chat_with = isset($_GET['chat_with']) ? (int)$_GET['chat_with'] : null;
$listing_id = isset($_GET['listing_id']) ? (int)$_GET['listing_id'] : null;

require_once __DIR__ . '/includes/header.php';

// Conversations list
$stmt_list = $pdo->prepare("
    SELECT
        m.message_text, m.created_at,
        u.full_name as contact_name,
        l.title as listing_title,
        l.id as l_id,
        u.id as u_id,
        (SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND sender_id = u.id AND listing_id = l.id AND is_read = 0) as unread_count
    FROM messages m
    JOIN users u ON (m.sender_id = u.id OR m.receiver_id = u.id) AND u.id != ?
    JOIN listings l ON m.listing_id = l.id
    WHERE (m.sender_id = ? OR m.receiver_id = ?)
    AND m.id IN (
        SELECT MAX(id) FROM messages
        WHERE sender_id = ? OR receiver_id = ?
        GROUP BY listing_id,
        CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END
    )
    ORDER BY m.created_at DESC
");
$stmt_list->execute([$current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id]);
$my_chats = $stmt_list->fetchAll();

$active_messages = [];
$active_listing = null;
$target_user_info = null;
$last_msg_id = 0;

if ($chat_with && $listing_id) {
    // Mark as read
    $pdo->prepare("UPDATE messages SET is_read = 1 WHERE listing_id = ? AND receiver_id = ? AND sender_id = ? AND is_read = 0")
        ->execute([$listing_id, $current_user_id, $chat_with]);

    $stmt_msg = $pdo->prepare("
        SELECT m.*, u.full_name
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.listing_id = ? AND
        ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
        ORDER BY m.created_at ASC
    ");
    $stmt_msg->execute([$listing_id, $current_user_id, $chat_with, $chat_with, $current_user_id]);
    $active_messages = $stmt_msg->fetchAll();

    if (!empty($active_messages)) {
        $last_msg_id = (int)end($active_messages)['id'];
    }

    $stmt_l = $pdo->prepare("SELECT l.*, (SELECT image_path FROM listing_images WHERE listing_id = l.id LIMIT 1) as main_img FROM listings l WHERE l.id = ?");
    $stmt_l->execute([$listing_id]);
    $active_listing = $stmt_l->fetch();

    $stmt_u = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt_u->execute([$chat_with]);
    $target_user_info = $stmt_u->fetch();
}
?>

<style>
    .custom-scrollbar::-webkit-scrollbar { width: 4px; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.05); border-radius: 10px; }
    #msg-container { scroll-behavior: smooth; }
    .animate-fade-in { animation: fadeIn 0.2s ease-out; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

    /* Layout Fix for Footer Visibility */
    .messages-container {
        display: flex;
        background-color: #f8fafc;
        font-family: sans-serif;
        height: 700px;
        max-height: 80vh;
        margin: 20px auto;
        border-radius: 24px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        width: 100%;
        max-width: 1400px;
    }
    @media (max-width: 768px) {
        .messages-container {
            height: calc(100vh - 80px);
            margin: 0;
            border-radius: 0;
            border: none;
            max-height: none;
        }
    }
</style>

<div class="messages-container" x-data="{ sidebarOpen: <?= ($chat_with) ? 'false' : 'true' ?> }">

    <!-- Sidebar -->
    <aside class="absolute inset-y-0 left-0 z-30 w-full md:relative md:w-80 lg:w-96 bg-white border-r border-slate-200 shadow-xl md:shadow-none transform transition-transform duration-300 ease-in-out md:translate-x-0"
           :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">
        <div class="h-full flex flex-col">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-white">
                <h1 class="text-xl font-black text-slate-900 tracking-tight">Mesajlarım</h1>
                <button @click="sidebarOpen = false" class="md:hidden p-2 text-slate-400 hover:bg-slate-50 rounded-full"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="flex-grow overflow-y-auto custom-scrollbar">
                <?php if(empty($my_chats)): ?>
                    <div class="p-10 text-center text-slate-400">
                        <span class="material-symbols-outlined text-4xl mb-2 opacity-20">forum</span>
                        <p class="text-xs">Hələ heç bir söhbət yoxdur.</p>
                    </div>
                <?php else: ?>
                    <?php foreach($my_chats as $chat): ?>
                        <a href="messages.php?chat_with=<?= $chat['u_id'] ?>&listing_id=<?= $chat['l_id'] ?>"
                           class="flex items-center gap-3 p-4 border-b border-slate-50 hover:bg-slate-50 transition-all
                           <?= ($chat_with == $chat['u_id'] && $listing_id == $chat['l_id']) ? 'bg-primary/5 border-l-4 border-l-primary' : '' ?>">
                            <div class="w-12 h-12 rounded-2xl bg-slate-100 flex-shrink-0 flex items-center justify-center text-slate-400 relative">
                                <span class="material-symbols-outlined text-2xl">person</span>
                                <?php if($chat['unread_count'] > 0): ?>
                                    <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-600 text-white text-[10px] font-bold rounded-full flex items-center justify-center border-2 border-white"><?= $chat['unread_count'] ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex justify-between items-center mb-0.5">
                                    <h4 class="font-bold text-slate-900 truncate text-sm"><?= htmlspecialchars($chat['contact_name']) ?></h4>
                                    <span class="text-[9px] text-slate-400 font-bold"><?= date('H:i', strtotime($chat['created_at'])) ?></span>
                                </div>
                                <p class="text-[11px] text-primary font-bold truncate uppercase tracking-tight"><?= htmlspecialchars($chat['listing_title']) ?></p>
                                <p class="text-xs text-slate-500 truncate"><?= htmlspecialchars($chat['message_text']) ?></p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </aside>

    <!-- Chat Area -->
    <main class="flex-grow flex flex-col min-w-0 bg-white relative">
        <?php if($chat_with && $active_listing): ?>
            <header class="h-16 sm:h-20 bg-white border-b border-slate-100 px-4 flex items-center justify-between sticky top-0 z-20">
                <div class="flex items-center gap-3">
                    <button @click="sidebarOpen = true" class="p-2 text-slate-600 hover:bg-slate-50 rounded-xl transition-all"><span class="material-symbols-outlined">menu_open</span></button>
                    <div class="min-w-0">
                        <h2 class="text-sm sm:text-base font-bold text-slate-900 truncate"><?= htmlspecialchars($target_user_info['full_name'] ?? 'İstifadəçi') ?></h2>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <a href="listing.php?id=<?= $active_listing['id'] ?>" class="flex items-center gap-2 p-1 rounded-2xl hover:bg-slate-50 transition-all border border-transparent hover:border-slate-100">
                        <div class="text-right hidden sm:block pr-1">
                            <p class="text-[10px] font-bold text-slate-900 truncate max-w-[150px]"><?= htmlspecialchars($active_listing['title']) ?></p>
                            <p class="text-[10px] font-black text-primary"><?= number_format($active_listing['price'], 0) ?> AZN</p>
                        </div>
                        <img src="<?= $active_listing['main_img'] ?: '/assets/img/no-image.png' ?>" class="w-10 h-10 rounded-xl object-cover border border-slate-100">
                    </a>
                </div>
            </header>

            <div class="flex-grow overflow-y-auto p-4 sm:p-6 space-y-4 bg-slate-50/30 custom-scrollbar" id="msg-container">
                <?php foreach($active_messages as $msg): ?>
                    <div class="flex <?= $msg['sender_id'] == $current_user_id ? 'justify-end' : 'justify-start' ?>" data-msg-id="<?= $msg['id'] ?>">
                        <div class="max-w-[85%] sm:max-w-[70%] px-4 py-3 rounded-2xl text-[13px] sm:text-sm shadow-sm
                            <?= $msg['sender_id'] == $current_user_id ? 'bg-primary text-white rounded-tr-none' : 'bg-white text-slate-700 border border-slate-100 rounded-tl-none' ?>">
                            <div class="whitespace-pre-wrap break-words"><?= htmlspecialchars($msg['message_text']) ?></div>
                            <div class="text-[9px] mt-1.5 opacity-60 text-right font-bold"><?= date('H:i', strtotime($msg['created_at'])) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Chat Form -->
            <div class="p-4 bg-white border-t border-slate-100">
                <div class="max-w-3xl mx-auto">
                    <form id="chat-form"
                          class="flex gap-2 items-center bg-slate-50 p-1.5 pl-4 rounded-full border border-slate-200 focus-within:border-primary/30 transition-all"
                          x-data="{
                              message: '',
                              async send() {
                                  if(!this.message.trim()) return;
                                  const text = this.message.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                                  this.message = '';
                                  this.$refs.textarea.style.height = 'auto';
                                  await sendMessage(text);
                              }
                          }">
                        <div class="flex-grow">
                            <textarea name="message_text"
                                      x-ref="textarea"
                                      x-model="message"
                                      @keydown.enter.prevent="send()"
                                      placeholder="Mesaj yaz..."
                                      class="w-full bg-transparent border-none focus:ring-0 text-[13px] py-1 resize-none max-h-24 outline-none text-slate-600 font-normal placeholder:text-slate-400"
                                      @input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"></textarea>
                        </div>
                        <button type="button"
                                @click="send()"
                                class="w-10 h-10 bg-primary text-white rounded-full flex-shrink-0 flex items-center justify-center hover:scale-105 active:scale-95 transition-all shadow-md">
                            <span class="material-symbols-outlined text-[20px]">send</span>
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="h-full flex flex-col items-center justify-center text-center p-8 bg-slate-50">
                <button @click="sidebarOpen = true" class="md:hidden absolute top-6 left-6 p-4 bg-white shadow-xl rounded-2xl text-primary"><span class="material-symbols-outlined">menu_open</span></button>
                <div class="w-24 h-24 bg-white rounded-[2.5rem] shadow-2xl shadow-primary/10 flex items-center justify-center mb-8 text-primary/20 rotate-12"><span class="material-symbols-outlined text-6xl">chat_bubble</span></div>
                <h3 class="text-xl font-black text-slate-800 tracking-tight">Söhbət Seçilməyib</h3>
                <p class="text-sm text-slate-400 mt-3 max-w-[260px] leading-relaxed">Digər mesajları görmək və ya söhbətə başlamaq üçün menyunu açın.</p>
                <button @click="sidebarOpen = true" class="mt-6 px-6 py-3 bg-white border border-slate-200 rounded-2xl text-xs font-bold text-slate-600 hover:bg-slate-100 transition-all shadow-sm">Siyahını Göstər</button>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
let lastMsgId = <?= (int)$last_msg_id ?>;
const msgContainer = document.getElementById('msg-container');

function scrollToBottom() { if(msgContainer) msgContainer.scrollTop = msgContainer.scrollHeight; }
window.addEventListener('load', scrollToBottom);

async function sendMessage(text) {
    const tempId = 'temp-' + Date.now();
    appendMessage({ id: tempId, message_text: text, sender_id: <?= $current_user_id ?>, created_at: new Date().toISOString() });

    const formData = new FormData();
    formData.append('message_text', text);
    formData.append('target_user', '<?= (int)$chat_with ?>');
    formData.append('target_listing', '<?= (int)$listing_id ?>');

    try {
        const response = await fetch('send_message.php', { method: 'POST', body: formData });
        const result = await response.json();
        if (result.status === 'success') {
            const tempEl = document.querySelector(`[data-msg-id="${tempId}"]`);
            if (tempEl) tempEl.setAttribute('data-msg-id', result.message_id);
            lastMsgId = Math.max(lastMsgId, parseInt(result.message_id));
        } else {
            alert('Mesaj göndərilə bilmədi: ' + result.message);
        }
    } catch (e) { console.error('Network error:', e); }
}

function appendMessage(msg) {
    if(document.querySelector(`[data-msg-id="${msg.id}"]`)) return;
    const isMe = msg.sender_id == <?= $current_user_id ?>;
    const time = new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const html = `<div class="flex ${isMe ? 'justify-end' : 'justify-start'} animate-fade-in" data-msg-id="${msg.id}"><div class="max-w-[85%] sm:max-w-[70%] px-4 py-3 rounded-2xl text-[13px] sm:text-sm shadow-sm ${isMe ? 'bg-primary text-white rounded-tr-none' : 'bg-white text-slate-700 border border-slate-100 rounded-tl-none'}"><div class="whitespace-pre-wrap break-words">${escapeHtml(msg.message_text)}</div><div class="text-[9px] mt-1.5 opacity-60 text-right font-bold">${time}</div></div></div>`;
    msgContainer.insertAdjacentHTML('beforeend', html);
    scrollToBottom();
}
function escapeHtml(text) { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }

<?php if($chat_with && $listing_id): ?>
setInterval(async () => {
    try {
        const response = await fetch(`fetch_messages.php?chat_with=<?= $chat_with ?>&listing_id=<?= $listing_id ?>&last_id=${lastMsgId}`);
        const data = await response.json();
        if(data && data.length > 0) {
            data.forEach(msg => {
                appendMessage(msg);
                lastMsgId = Math.max(lastMsgId, parseInt(msg.id));
            });
        }
    } catch (e) { console.error('Poll error:', e); }
}, 3000);
<?php endif; ?>
</script>
