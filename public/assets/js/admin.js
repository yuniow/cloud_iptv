document.addEventListener('DOMContentLoaded', () => {
    lucide.createIcons();

    let allChannels = [];
    let externalConfig = { enabled: true, sources: [] };
    let builtInConfig = { enabled: true, sources: [] };
    let systemConfig = {};
    let myPlaylistConfig = {};
    let myPlaylistData = [];
    let selectedGroupIndex = 0;
    let isViewingDeleted = false;
    let selectedExternalIndex = null;
    let updatingSourceIndex = null;
    let currentTab = 0;

    const API_PREFIX = '';

    initTabs();
    loadData();

    document.getElementById('modalClose').addEventListener('click', closeModal);
    document.getElementById('modalOverlay').addEventListener('click', (e) => {
        if (e.target === e.currentTarget) closeModal();
    });

    function initTabs() {
        document.querySelectorAll('.sidebar-item').forEach((item, i) => {
            item.addEventListener('click', () => {
                currentTab = i;
                document.querySelectorAll('.sidebar-item').forEach(s => s.classList.remove('active'));
                item.classList.add('active');
                document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
                const tabs = ['tab-channels', 'tab-sources', 'tab-config'];
                document.getElementById(tabs[i]).classList.add('active');
                if (i === 0) loadMyPlaylist();
            });
        });
    }

    async function loadData() {
        try {
            const [channelsRes, externalRes, builtInRes, systemRes] = await Promise.all([
                fetch(API_PREFIX + '/api/channels'),
                fetch(API_PREFIX + '/api/external-sources'),
                fetch(API_PREFIX + '/api/built-in-sources'),
                fetch(API_PREFIX + '/api/system-config'),
            ]);

            allChannels = await channelsRes.json();
            const externalPayload = await externalRes.json();
            externalConfig = normalizeExternalConfig(externalPayload);
            const builtInPayload = await builtInRes.json();
            builtInConfig = builtInPayload.data || { enabled: true, sources: [] };
            systemConfig = await systemRes.json();

            await loadMyPlaylistConfig();
            await loadMyPlaylist();
            renderExternalSources();
            renderSystemConfig();
        } catch (error) {
            console.error('加载数据失败:', error);
        }
    }

    function normalizeExternalConfig(payload) {
        const data = payload && payload.data !== undefined ? payload.data : payload || {};
        if (Array.isArray(data)) {
            return { enabled: true, includeInPlaylists: true, updateOnStartup: true, sources: data };
        }
        return {
            enabled: true,
            includeInPlaylists: data.includeInPlaylists !== false,
            updateOnStartup: data.updateOnStartup !== false,
            sources: Array.isArray(data.sources) ? data.sources : []
        };
    }

    async function loadMyPlaylist() {
        try {
            const res = await fetch(API_PREFIX + '/api/my-playlist');
            const data = await res.json();
            if (data.success) {
                myPlaylistData = data.data || [];
                renderMyPlaylist();
            }
        } catch (error) {
            console.error('加载我的频道失败:', error);
        }
    }

    async function loadMyPlaylistConfig() {
        try {
            const res = await fetch(API_PREFIX + '/api/my-playlist-config');
            const data = await res.json();
            if (data.success) {
                myPlaylistConfig = data.data || {};
            }
        } catch (error) {
            console.error('加载播放列表配置失败:', error);
        }
    }

    function renderMyPlaylist() {
        const container = document.getElementById('channelManagement');
        const totalCount = document.getElementById('totalChannelCount');
        if (!container) return;

        container.innerHTML = '';
        let total = 0;

        myPlaylistData.forEach((group, gi) => {
            total += group.channels ? group.channels.length : 0;
            const section = document.createElement('div');
            section.className = 'group-section';
            section.innerHTML = `
                <div class="group-header">
                    <div class="group-name">
                        ${group.name}
                        <span class="group-count">(${group.channels ? group.channels.length : 0})</span>
                    </div>
                    <div class="group-actions">
                        <button class="icon-btn" title="上移" onclick="moveGroupUp(${gi})"><i data-lucide="chevron-up" style="width:14px;height:14px;"></i></button>
                        <button class="icon-btn" title="下移" onclick="moveGroupDown(${gi})"><i data-lucide="chevron-down" style="width:14px;height:14px;"></i></button>
                        <button class="icon-btn" title="重命名" onclick="renameGroup(${gi})"><i data-lucide="pencil" style="width:14px;height:14px;"></i></button>
                    </div>
                </div>
            `;

            if (group.channels) {
                group.channels.forEach(ch => {
                    const item = document.createElement('div');
                    item.className = 'channel-item';
                    item.innerHTML = `
                        <div>
                            <div class="channel-name">${ch.name}</div>
                            <div class="channel-source">${ch.originalGroup || ''}</div>
                        </div>
                        <div class="channel-actions">
                            <button class="icon-btn" title="移动" onclick="moveCurrentChannel('${ch.originalGroup}::${ch.id}')"><i data-lucide="folder-input" style="width:14px;height:14px;"></i></button>
                            <button class="icon-btn" title="重命名" onclick="renameCurrentChannel('${ch.originalGroup}::${ch.id}', '${ch.name.replace(/'/g, "\\'")}')"><i data-lucide="pencil" style="width:14px;height:14px;"></i></button>
                            <button class="icon-btn" title="隐藏" onclick="hideChannel('${ch.originalGroup}::${ch.id}')"><i data-lucide="eye-off" style="width:14px;height:14px;"></i></button>
                        </div>
                    `;
                    section.appendChild(item);
                });
            }

            container.appendChild(section);
        });

        if (totalCount) totalCount.textContent = total;
        lucide.createIcons();
    }

    window.moveGroupUp = async function(index) {
        if (index <= 0) return;
        const order = myPlaylistData.map(g => g.name);
        [order[index - 1], order[index]] = [order[index], order[index - 1]];
        myPlaylistConfig.groupOrder = order;
        await saveMyPlaylistConfig();
        await loadMyPlaylist();
    };

    window.moveGroupDown = async function(index) {
        if (index >= myPlaylistData.length - 1) return;
        const order = myPlaylistData.map(g => g.name);
        [order[index], order[index + 1]] = [order[index + 1], order[index]];
        myPlaylistConfig.groupOrder = order;
        await saveMyPlaylistConfig();
        await loadMyPlaylist();
    };

    window.renameGroup = async function(index) {
        const group = myPlaylistData[index];
        if (!group) return;
        const newName = prompt('重命名分组', group.name);
        if (!newName || newName === group.name) return;
        myPlaylistConfig.groupRenameMap = myPlaylistConfig.groupRenameMap || {};
        myPlaylistConfig.groupRenameMap[group.name] = newName;
        await saveMyPlaylistConfig();
        await loadMyPlaylist();
    };

    window.hideChannel = async function(channelKey) {
        myPlaylistConfig.hiddenChannels = myPlaylistConfig.hiddenChannels || [];
        if (!myPlaylistConfig.hiddenChannels.includes(channelKey)) {
            myPlaylistConfig.hiddenChannels.push(channelKey);
        }
        await saveMyPlaylistConfig();
        await loadMyPlaylist();
    };

    window.moveCurrentChannel = function(channelKey) {
        const targetGroup = prompt('移动到分组（输入分组名）');
        if (!targetGroup) return;
        myPlaylistConfig.channelGroupMap = myPlaylistConfig.channelGroupMap || {};
        myPlaylistConfig.channelGroupMap[channelKey] = targetGroup;
        saveMyPlaylistConfig().then(() => loadMyPlaylist());
    };

    window.renameCurrentChannel = function(channelKey, currentName) {
        const newName = prompt('重命名频道', currentName);
        if (!newName || newName === currentName) return;
        myPlaylistConfig.channelRenameMap = myPlaylistConfig.channelRenameMap || {};
        myPlaylistConfig.channelRenameMap[channelKey] = newName;
        saveMyPlaylistConfig().then(() => loadMyPlaylist());
    };

    async function saveMyPlaylistConfig() {
        try {
            await fetch(API_PREFIX + '/api/my-playlist-config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(myPlaylistConfig),
            });
        } catch (error) {
            console.error('保存配置失败:', error);
        }
    }

    function renderExternalSources() {
        const list = document.getElementById('sourceList');
        if (!list) return;

        if (!externalConfig.sources || externalConfig.sources.length === 0) {
            list.innerHTML = '<div class="empty-state" style="padding:20px;color:var(--text-tertiary);">暂无外部源，点击"添加源"开始配置</div>';
            return;
        }

        list.innerHTML = '';
        externalConfig.sources.forEach((source, index) => {
            const item = document.createElement('div');
            item.className = 'source-item';
            const modeText = source.mode === 'fetch' ? '抓取' : source.mode === 'subscription' ? '订阅' : '直连';
            const channelCount = source.parsedChannels ? source.parsedChannels.length : 0;
            item.innerHTML = `
                <div class="source-info">
                    <div class="source-name">${source.name || '未命名源'}</div>
                    <div class="source-meta">
                        <span class="badge">${modeText}</span>
                        <span>${source.group || '未分组'}</span>
                        ${channelCount > 0 ? `<span>${channelCount} 频道</span>` : ''}
                        ${source.lastUpdated ? `<span>${new Date(source.lastUpdated).toLocaleString()}</span>` : ''}
                    </div>
                </div>
                <div class="source-actions">
                    <button class="icon-btn" title="编辑" onclick="editSource(${index})"><i data-lucide="pencil"></i></button>
                    <button class="icon-btn" title="刷新" onclick="refreshSource(${index})"><i data-lucide="refresh-cw"></i></button>
                    <button class="icon-btn" title="删除" onclick="deleteSource(${index})"><i data-lucide="trash-2"></i></button>
                </div>
            `;
            list.appendChild(item);
        });
        lucide.createIcons();
    }

    document.getElementById('addSourceBtn').addEventListener('click', () => {
        showAddSourceModal();
    });

    function showAddSourceModal() {
        document.getElementById('modalTitle').textContent = '添加外部源';
        document.getElementById('modalBody').innerHTML = `
            <div class="mode-tabs">
                <button class="mode-tab active" data-mode="direct" onclick="switchMode('direct')">直连模式</button>
                <button class="mode-tab" data-mode="subscription" onclick="switchMode('subscription')">订阅模式</button>
                <button class="mode-tab" data-mode="fetch" onclick="switchMode('fetch')">抓取模式</button>
            </div>
            <div class="form-section active" id="section-direct">
                <div class="form-group"><label>频道名称</label><input type="text" id="sourceName" placeholder="例：体育频道"></div>
                <div class="form-group"><label>分组</label><input type="text" id="sourceGroup" value="未分组"></div>
                <div class="form-group"><label>M3U8 地址</label><input type="text" id="sourceUrl" placeholder="http://..."></div>
            </div>
            <div class="form-section" id="section-subscription">
                <div class="form-group"><label>订阅名称</label><input type="text" id="subName" placeholder="例：港澳频道"></div>
                <div class="form-group"><label>订阅地址 (m3u/m3u8/txt)</label><input type="text" id="subUrl" placeholder="http://..."></div>
                <div class="form-group"><label>刷新间隔 (分钟)</label><input type="number" id="subInterval" value="1440"></div>
            </div>
            <div class="form-section" id="section-fetch">
                <div class="form-group"><label>频道名称</label><input type="text" id="fetchName" placeholder="例：纬来体育"></div>
                <div class="form-group"><label>分组</label><input type="text" id="fetchGroup" value="体育"></div>
                <div class="form-group"><label>网页地址</label><input type="text" id="fetchUrl" placeholder="http://..."></div>
            </div>
            <div class="form-actions">
                <button class="btn-secondary" onclick="closeModal()">取消</button>
                <button class="btn-primary" onclick="confirmAddSource()">保存</button>
            </div>
        `;
        openModal();
    }

    window.switchMode = function(mode) {
        document.querySelectorAll('.mode-tab').forEach(t => t.classList.remove('active'));
        document.querySelector(`.mode-tab[data-mode="${mode}"]`).classList.add('active');
        document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));
        document.getElementById(`section-${mode}`).classList.add('active');
    };

    window.confirmAddSource = async function() {
        const activeMode = document.querySelector('.mode-tab.active').dataset.mode;
        let payload = { action: 'add', source: { mode: activeMode, enabled: true } };

        if (activeMode === 'direct') {
            payload.source.name = document.getElementById('sourceName').value.trim();
            payload.source.group = document.getElementById('sourceGroup').value.trim() || '未分组';
            payload.source.m3u8Url = document.getElementById('sourceUrl').value.trim();
            if (!payload.source.name || !payload.source.m3u8Url) { alert('请填写完整信息'); return; }
        } else if (activeMode === 'subscription') {
            payload.source.name = document.getElementById('subName').value.trim();
            payload.source.subscriptionUrl = document.getElementById('subUrl').value.trim();
            payload.source.refreshInterval = parseInt(document.getElementById('subInterval').value) || 1440;
            payload.source.group = '未分组';
            if (!payload.source.name || !payload.source.subscriptionUrl) { alert('请填写完整信息'); return; }
        } else if (activeMode === 'fetch') {
            payload.source.name = document.getElementById('fetchName').value.trim();
            payload.source.group = document.getElementById('fetchGroup').value.trim() || '体育';
            payload.source.webUrl = document.getElementById('fetchUrl').value.trim();
            if (!payload.source.name || !payload.source.webUrl) { alert('请填写完整信息'); return; }
        }

        try {
            const res = await fetch(API_PREFIX + '/api/external-sources', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (data.success) {
                closeModal();
                await loadData();
            } else {
                alert(data.message || '添加失败');
            }
        } catch (error) {
            alert('操作失败');
        }
    };

    window.editSource = function(index) {
        const source = externalConfig.sources[index];
        if (!source) return;
        selectedExternalIndex = index;
        const mode = source.mode || 'direct';

        document.getElementById('modalTitle').textContent = '编辑外部源';
        let formHtml = '';

        if (mode === 'direct') {
            formHtml = `
                <div class="form-group"><label>频道名称</label><input type="text" id="editName" value="${source.name || ''}"></div>
                <div class="form-group"><label>分组</label><input type="text" id="editGroup" value="${source.group || ''}"></div>
                <div class="form-group"><label>M3U8 地址</label><input type="text" id="editUrl" value="${source.m3u8Url || ''}"></div>
            `;
        } else if (mode === 'subscription') {
            formHtml = `
                <div class="form-group"><label>订阅名称</label><input type="text" id="editName" value="${source.name || ''}"></div>
                <div class="form-group"><label>订阅地址</label><input type="text" id="editSubUrl" value="${source.subscriptionUrl || ''}"></div>
                <div class="form-group"><label>刷新间隔 (分钟)</label><input type="number" id="editInterval" value="${source.refreshInterval || 1440}"></div>
            `;
        } else if (mode === 'fetch') {
            formHtml = `
                <div class="form-group"><label>频道名称</label><input type="text" id="editName" value="${source.name || ''}"></div>
                <div class="form-group"><label>分组</label><input type="text" id="editGroup" value="${source.group || ''}"></div>
                <div class="form-group"><label>网页地址</label><input type="text" id="editWebUrl" value="${source.webUrl || ''}"></div>
            `;
        }

        document.getElementById('modalBody').innerHTML = `
            ${formHtml}
            <div class="form-group"><label>
                <input type="checkbox" id="editEnabled" ${source.enabled !== false ? 'checked' : ''}> 启用此源
            </label></div>
            <div class="form-actions">
                <button class="btn-secondary" onclick="closeModal()">取消</button>
                <button class="btn-primary" onclick="confirmEditSource(${index}, '${mode}')">保存</button>
            </div>
        `;
        openModal();
    };

    window.confirmEditSource = async function(index, mode) {
        const source = externalConfig.sources[index];
        const payload = { action: 'save', sources: [...externalConfig.sources] };

        payload.sources[index].name = document.getElementById('editName')?.value.trim() || source.name;
        payload.sources[index].group = document.getElementById('editGroup')?.value.trim() || source.group;
        payload.sources[index].enabled = document.getElementById('editEnabled')?.checked ?? true;

        if (mode === 'direct') {
            payload.sources[index].m3u8Url = document.getElementById('editUrl')?.value.trim() || '';
        } else if (mode === 'subscription') {
            payload.sources[index].subscriptionUrl = document.getElementById('editSubUrl')?.value.trim() || '';
            payload.sources[index].refreshInterval = parseInt(document.getElementById('editInterval')?.value) || 1440;
        } else if (mode === 'fetch') {
            payload.sources[index].webUrl = document.getElementById('editWebUrl')?.value.trim() || '';
        }

        try {
            const res = await fetch(API_PREFIX + '/api/external-sources', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (data.success) {
                closeModal();
                await loadData();
            } else {
                alert(data.message || '保存失败');
            }
        } catch (error) {
            alert('操作失败');
        }
    };

    window.refreshSource = async function(index) {
        try {
            const res = await fetch(API_PREFIX + '/api/external-sources', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update', index }),
            });
            const data = await res.json();
            alert(data.message || (data.success ? '刷新完成' : '刷新失败'));
            await loadData();
        } catch (error) {
            alert('刷新失败');
        }
    };

    window.deleteSource = async function(index) {
        if (!confirm('确定要删除此源吗？')) return;
        try {
            const res = await fetch(API_PREFIX + '/api/external-sources', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'remove', index }),
            });
            const data = await res.json();
            if (data.success) {
                await loadData();
            }
        } catch (error) {
            alert('删除失败');
        }
    };

    function renderSystemConfig() {
        const form = document.getElementById('configForm');
        if (!form || !systemConfig.data) return;
        const data = systemConfig.data;

        const fields = ['userId', 'token', 'port', 'host', 'pass', 'adminPath', 'programInfoUpdateInterval'];
        fields.forEach(key => {
            const input = form.querySelector(`[name="${key}"]`);
            if (input) input.value = data[key] ?? '';
        });

        const selects = ['rateType'];
        selects.forEach(key => {
            const select = form.querySelector(`[name="${key}"]`);
            if (select) select.value = data[key] ?? '';
        });

        const checks = ['enableHDR', 'enableH265', 'enableMigu', 'enableBuiltInSources', 'enableBuiltInSubscriptions', 'refreshToken'];
        checks.forEach(key => {
            const cb = form.querySelector(`[name="${key}"]`);
            if (cb) cb.checked = !!data[key];
        });
    }

    document.getElementById('configForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const data = {};
        new FormData(form).forEach((value, key) => {
            const input = form.querySelector(`[name="${key}"]`);
            if (input && input.type === 'checkbox') {
                data[key] = input.checked;
            } else {
                data[key] = value;
            }
        });

        try {
            const res = await fetch(API_PREFIX + '/api/system-config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data),
            });
            const result = await res.json();
            alert(result.message || (result.success ? '保存成功' : '保存失败'));
            if (result.success) await loadData();
        } catch (error) {
            alert('保存失败');
        }
    });

    function openModal() {
        document.getElementById('modalOverlay').classList.add('active');
    }

    function closeModal() {
        document.getElementById('modalOverlay').classList.remove('active');
    }

    window.closeModal = closeModal;
});
