document.addEventListener('DOMContentLoaded', () => {
    lucide.createIcons();

    let allChannels = {};
    let currentGroup = 'all';
    let hlsInstance = null;

    loadChannels();

    document.getElementById('searchInput').addEventListener('input', (e) => {
        renderChannels(e.target.value);
    });

    document.getElementById('refreshBtn').addEventListener('click', () => {
        loadChannels();
    });

    document.getElementById('modalClose').addEventListener('click', closeModal);
    document.getElementById('modalOverlay').addEventListener('click', (e) => {
        if (e.target === e.currentTarget) closeModal();
    });

    async function loadChannels() {
        try {
            const res = await fetch('/api/my-playlist');
            const data = await res.json();
            if (data.success) {
                allChannels = {};
                (data.data || []).forEach(g => {
                    allChannels[g.name] = g.channels || [];
                });
                renderSidebar();
                renderChannels();
                document.getElementById('channelCount').textContent = data.data
                    ? data.data.reduce((sum, g) => sum + (g.channels ? g.channels.length : 0), 0)
                    : 0;
            }
        } catch (err) {
            console.error('加载频道失败:', err);
        }
    }

    function renderSidebar() {
        const nav = document.getElementById('sidebarNav');
        const groups = Object.keys(allChannels);

        nav.innerHTML = `
            <div class="sidebar-item ${currentGroup === 'all' ? 'active' : ''}" data-group="all">
                <i data-lucide="tv"></i>
                <span>全部频道</span>
            </div>
        `;

        groups.forEach(group => {
            const count = allChannels[group].length;
            nav.innerHTML += `
                <div class="sidebar-item ${currentGroup === group ? 'active' : ''}" data-group="${group}">
                    <i data-lucide="${getGroupIcon(group)}"></i>
                    <span>${group} (${count})</span>
                </div>
            `;
        });

        nav.querySelectorAll('.sidebar-item').forEach(item => {
            item.addEventListener('click', () => {
                currentGroup = item.dataset.group;
                document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));
                item.classList.add('active');
                document.getElementById('currentGroupTitle').textContent =
                    currentGroup === 'all' ? '全部频道' : currentGroup;
                renderChannels();
            });
        });

        renderMobileTabs();
        lucide.createIcons();
    }

    function renderMobileTabs() {
        const tabBar = document.getElementById('mobileTabBar');
        const groups = Object.keys(allChannels).slice(0, 5);

        tabBar.innerHTML = `
            <div class="tab-item ${currentGroup === 'all' ? 'active' : ''}" data-group="all">
                <i data-lucide="tv"></i>
                <span>全部</span>
            </div>
        `;

        groups.forEach(group => {
            tabBar.innerHTML += `
                <div class="tab-item ${currentGroup === group ? 'active' : ''}" data-group="${group}">
                    <i data-lucide="${getGroupIcon(group)}"></i>
                    <span>${group.length > 4 ? group.substring(0, 4) : group}</span>
                </div>
            `;
        });

        tabBar.querySelectorAll('.tab-item').forEach(item => {
            item.addEventListener('click', () => {
                currentGroup = item.dataset.group;
                document.querySelectorAll('.tab-item').forEach(i => i.classList.remove('active'));
                item.classList.add('active');
                document.getElementById('currentGroupTitle').textContent =
                    currentGroup === 'all' ? '全部频道' : currentGroup;
                renderChannels();
                document.querySelectorAll('.sidebar-item').forEach(i => {
                    i.classList.toggle('active', i.dataset.group === currentGroup);
                });
            });
        });
    }

    function renderChannels(filter = '') {
        const grid = document.getElementById('channelGrid');
        const emptyState = document.getElementById('emptyState');

        let channels = [];
        if (currentGroup === 'all') {
            Object.values(allChannels).forEach(arr => { channels = channels.concat(arr); });
        } else {
            channels = allChannels[currentGroup] || [];
        }

        if (filter) {
            const q = filter.toLowerCase();
            channels = channels.filter(ch => {
                const name = (ch.display_name || ch.name || '').toLowerCase();
                return name.includes(q);
            });
        }

        if (channels.length === 0) {
            grid.innerHTML = '';
            emptyState.style.display = 'flex';
            return;
        }

        emptyState.style.display = 'none';

        grid.innerHTML = channels.map((ch, i) => {
            const name = ch.display_name || ch.name || '';
            const group = ch.originalGroup || '';
            const logo = ch.logo || '';
            const url = ch.url || '';
            const initial = name.substring(0, 2);

            return `
                <div class="channel-card fade-in" style="animation-delay:${Math.min(i * 20, 500)}ms" data-id="${ch.id}">
                    <div class="channel-card-logo">
                        ${logo
                            ? `<img src="${logo}" alt="${name}" onerror="this.style.display='none';this.nextElementSibling.style.display='block'"><span style="display:none;color:var(--accent-blue);font-size:28px;font-weight:600;">${initial}</span>`
                            : `<span style="color:var(--accent-blue);font-size:28px;font-weight:600;">${initial}</span>`
                        }
                    </div>
                    <div class="channel-card-info">
                        <div class="channel-card-name" title="${name}">${name}</div>
                        <div class="channel-card-group">${group}</div>
                    </div>
                    <div class="channel-card-actions">
                        <button class="btn-sm btn-play" onclick="event.stopPropagation();window.playChannel('${ch.id}','${name.replace(/'/g, "\\'")}','${url.replace(/'/g, "\\'")}')">
                            <i data-lucide="play" style="width:12px;height:12px;"></i> 播放
                        </button>
                        <button class="btn-sm btn-copy" onclick="event.stopPropagation();window.copyChannelUrl('${ch.id}')">
                            <i data-lucide="copy" style="width:12px;height:12px;"></i> 复制
                        </button>
                    </div>
                </div>
            `;
        }).join('');

        grid.querySelectorAll('.channel-card').forEach(card => {
            card.addEventListener('click', () => {
                const id = card.dataset.id;
                showChannelDetail(id);
            });
        });

        lucide.createIcons();
    }

    function getGroupIcon(group) {
        const icons = {
            '央视': 'tv', '体育': 'trophy', '影视': 'film', '新闻': 'newspaper',
            '国际': 'globe', '地方': 'map-pin', '音乐': 'music', '少儿': 'baby',
            '教育': 'book-open', '未分组': 'inbox', '亚太': 'globe', '港澳': 'map-pin',
            '综艺': 'music', '纪实': 'film', '4K': 'tv',
        };
        for (const [key, icon] of Object.entries(icons)) {
            if (group.includes(key)) return icon;
        }
        return 'folder';
    }

    function getChannelUrl(channelId) {
        return window.location.origin + '/' + channelId;
    }

    window.playChannel = function(channelId, name, url) {
        if (!url) {
            url = getChannelUrl(channelId);
        }
        if (url.includes('.m3u8') || url.includes('m3u8')) {
            playM3u8(url, name);
        } else {
            window.open(url, '_blank');
        }
    };

    function playM3u8(url, name) {
        const modal = document.getElementById('modal');
        document.getElementById('modalTitle').textContent = name || '播放中';
        document.getElementById('modalBody').innerHTML = `
            <div style="position:relative;width:100%;padding-top:56.25%;background:#000;border-radius:8px;overflow:hidden;">
                <video id="liveVideo" controls autoplay style="position:absolute;top:0;left:0;width:100%;height:100%;"></video>
            </div>
            <div style="margin-top:12px;display:flex;gap:8px;">
                <button class="btn-secondary" onclick="window.copyText('${url}')">
                    <i data-lucide="copy" style="width:14px;height:14px;"></i> 复制链接
                </button>
            </div>
        `;
        openModal();
        lucide.createIcons();

        const video = document.getElementById('liveVideo');
        if (Hls.isSupported()) {
            hlsInstance = new Hls({ maxBufferLength: 30 });
            hlsInstance.loadSource(url);
            hlsInstance.attachMedia(video);
            hlsInstance.on(Hls.Events.MANIFEST_PARSED, () => video.play().catch(() => {}));
        } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = url;
            video.play().catch(() => {});
        }
    }

    window.copyChannelUrl = function(channelId) {
        const url = getChannelUrl(channelId);
        navigator.clipboard.writeText(url).then(() => {
            showToast('链接已复制', 'success');
        }).catch(() => {
            showToast('复制失败', 'error');
        });
    };

    window.copyText = function(text) {
        navigator.clipboard.writeText(text).then(() => {
            showToast('已复制', 'success');
        }).catch(() => {
            showToast('复制失败', 'error');
        });
    };

    function showChannelDetail(channelId) {
        let found = null;
        let foundGroup = '';
        for (const [group, channels] of Object.entries(allChannels)) {
            const ch = channels.find(c => c.id === channelId);
            if (ch) { found = ch; foundGroup = group; break; }
        }
        if (!found) return;

        const name = found.display_name || found.name || '';
        const url = found.url || getChannelUrl(channelId);
        const logo = found.logo || '';
        const tvgId = found.tvgId || '';

        document.getElementById('modalTitle').textContent = name;
        document.getElementById('modalBody').innerHTML = `
            <div style="display:flex;flex-direction:column;gap:16px;">
                <div style="display:flex;gap:16px;align-items:center;">
                    ${logo ? `<img src="${logo}" style="width:64px;height:64px;border-radius:8px;object-fit:contain;" onerror="this.style.display='none'">` : ''}
                    <div>
                        <div style="font-size:18px;font-weight:600;">${name}</div>
                        <div style="font-size:13px;color:var(--text-secondary);">${foundGroup}</div>
                        ${tvgId ? `<div style="font-size:12px;color:var(--text-tertiary);">ID: ${tvgId}</div>` : ''}
                    </div>
                </div>
                <div class="form-group">
                    <label>播放地址</label>
                    <input type="text" value="${url}" readonly style="font-size:12px;">
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="btn-primary" onclick="window.playChannel('${found.id}','${name.replace(/'/g, "\\'")}','${url.replace(/'/g, "\\'")}')">
                        <i data-lucide="play"></i> 播放
                    </button>
                    <button class="btn-secondary" onclick="window.copyText('${url}')">
                        <i data-lucide="copy"></i> 复制链接
                    </button>
                    <button class="btn-secondary" onclick="window.open('vlc://${url}', '_blank')">
                        VLC
                    </button>
                </div>
            </div>
        `;
        openModal();
        lucide.createIcons();
    }

    function openModal() {
        document.getElementById('modalOverlay').classList.add('active');
    }

    function closeModal() {
        document.getElementById('modalOverlay').classList.remove('active');
        if (hlsInstance) { hlsInstance.destroy(); hlsInstance = null; }
        const video = document.getElementById('liveVideo');
        if (video) { video.src = ''; }
    }

    window.closeModal = closeModal;

    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }
});
