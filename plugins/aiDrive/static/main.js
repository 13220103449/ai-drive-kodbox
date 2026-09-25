kodReady.push(function(){
	$.addStyle('.aidrive-agent-btn{color:#fff!important;background:#4f6ef7!important;border-color:#4f6ef7!important}.aidrive-create-form label{display:block;margin:12px 0 5px;color:#555}.aidrive-create-form input{width:100%;height:36px;padding:6px 10px;border:1px solid #ddd;border-radius:4px}.aidrive-bind-result textarea{width:100%;height:320px;resize:none;padding:12px;line-height:1.65;border:1px solid #ddd;border-radius:5px;background:#f8fafc}.aidrive-space-note{padding:10px 12px;background:#eef4ff;border-radius:5px;color:#456;margin-bottom:12px}.aidrive-cards{display:flex;gap:10px;margin-bottom:12px}.aidrive-card{flex:1;padding:12px;background:#f6f8fc;border-radius:6px;text-align:center}.aidrive-card b{display:block;font-size:22px;color:#345}');

	function showBinding(data){
		var text=data.copyPrompt||'';
		var html='<div class="aidrive-bind-result"><div class="aidrive-space-note"><b>绑定空间：</b>默认是“个人空间”；协作文件放在“我在的部门 / 智能体”。</div>'+
			'<p>以下连接信息只完整显示这一次。请复制整段话术发给对应的 Agent：</p><textarea readonly></textarea></div>';
		var dialog=$.dialog({title:'智能体账号已创建',width:680,height:510,content:html,okVal:'复制全部话术',ok:function(){$.copyText(text);Tips.tips('接入话术已复制');return false;},cancelVal:'完成',cancel:true});
		dialog.$main.find('textarea').val(text).on('click',function(){this.select();});
	}

	function showRotatedToken(data){
		var text=data.copyPrompt||'';var env='AI_DRIVE_URL='+data.apiUrl+'\nAI_DRIVE_TOKEN='+data.token+'\n';
		var html='<div class="aidrive-bind-result"><div class="aidrive-space-note"><b>新 Token 已生效，旧 Token 仍可使用 24 小时，便于平滑切换。</b><br>指纹：'+$('<div>').text(data.tokenFingerprint||'').html()+'</div>'+
			'<p>以下内容只显示这一次。将环境变量覆盖到对应 Agent 的私密配置中：</p><textarea readonly></textarea></div>';
		var dialog=$.dialog({title:'Agent Token 已重新生成',width:680,height:510,content:html,okVal:'复制环境变量',ok:function(){$.copyText(env);Tips.tips('环境变量已复制');return false;},cancelVal:'完成',cancel:true});
		dialog.$main.find('textarea').val(text+'\n\n环境变量：\n'+env).on('click',function(){this.select();});
	}

	function manageAgents(){
		$.when($.get('{{agentsApi}}'),$.get('{{dashboardApi}}')).done(function(agentResponse,dashboardResponse){var result=agentResponse[0],stats=dashboardResponse[0].data||{};
			if(!result.code){Tips.tips(result.data||'读取 Agent 失败','warning');return;}
			var rows=(result.data||[]).map(function(item){var safe=function(v){return $('<div>').text(v==null?'':v).html();};return '<tr><td>'+safe(item.name)+'</td><td>'+safe(item.username)+'</td><td>'+safe(item.tokenFingerprint)+'</td><td>'+safe(item.requestCount||0)+' / '+safe(item.failureCount||0)+'</td><td>'+(String(item.status)==='1'?'正常':'已停用')+'</td><td><button class="btn btn-sm aidrive-toggle" data-id="'+safe(item.agentID)+'" data-enabled="'+(String(item.status)==='1'?'0':'1')+'">'+(String(item.status)==='1'?'暂停':'恢复')+'</button> <button class="btn btn-sm aidrive-rotate-token" data-id="'+safe(item.agentID)+'">换钥</button></td></tr>';}).join('');
			var cards='<div class="aidrive-cards"><div class="aidrive-card"><b>'+Number(stats.agents||0)+'</b>Agent</div><div class="aidrive-card"><b>'+Number(stats.online||0)+'</b>近 5 分钟在线</div><div class="aidrive-card"><b>'+Number(stats.requests||0)+'</b>请求</div><div class="aidrive-card"><b>'+Number(stats.failures||0)+'</b>失败</div></div>';
			var html='<div class="aidrive-agent-list">'+cards+'<div class="aidrive-space-note">Token 指纹仅用于安全核对。换钥后旧 Token 保留 24 小时。</div><p><button class="btn btn-default aidrive-audit">查看操作日志</button> <button class="btn btn-default aidrive-version-list">文件版本记录</button></p><table class="table"><thead><tr><th>名称</th><th>账号</th><th>Token 指纹</th><th>请求/失败</th><th>状态</th><th>操作</th></tr></thead><tbody>'+rows+'</tbody></table></div>';
			var dialog=$.dialog({title:'Agent 密钥管理',width:900,height:460,content:html,cancelVal:'关闭',cancel:true});
			dialog.$main.on('click','.aidrive-rotate-token',function(){var id=$(this).data('id');$.dialog.confirm('将生成新 Token；旧 Token 会保留 24 小时用于迁移。确认继续？',function(){
				$.ajax({url:'{{agentsApi}}',type:'PATCH',contentType:'application/json',data:JSON.stringify({agentID:id}),dataType:'json'}).done(function(rotated){if(!rotated.code){Tips.tips(rotated.data||'重新生成失败','warning');return;}dialog.close();showRotatedToken(rotated.data);}).fail(function(xhr){Tips.tips((xhr.responseJSON&&xhr.responseJSON.data)||'重新生成失败','warning');});
			});});
			dialog.$main.on('click','.aidrive-toggle',function(){var id=$(this).data('id'),enabled=String($(this).data('enabled'))==='1';$.ajax({url:'{{agentsApi}}',type:'PATCH',contentType:'application/json',data:JSON.stringify({agentID:id,enabled:enabled}),dataType:'json'}).done(function(r){if(!r.code){Tips.tips(r.data||'操作失败','warning');return;}dialog.close();manageAgents();});});
			dialog.$main.on('click','.aidrive-audit',function(){$.get('{{auditApi}}',{limit:200}).done(function(r){var text=(r.data||[]).map(function(x){return new Date(Number(x.createTime)*1000).toLocaleString()+'  '+x.agentID+'  '+x.action+'  '+x.result+'  '+x.detail;}).join('\n');$.dialog({title:'Agent 操作日志',width:900,height:520,content:'<textarea readonly style="width:100%;height:430px">'+$('<div>').text(text).html()+'</textarea>',cancel:true});});});
			dialog.$main.on('click','.aidrive-version-list',function(){$.get('{{versionsApi}}',{limit:200}).done(function(r){var text=(r.data||[]).map(function(x){return '#'+x.id+'  '+new Date(Number(x.createTime)*1000).toLocaleString()+'  '+x.agentID+'  '+x.operation+'  '+x.path+'  '+x.size+' bytes';}).join('\n');$.dialog({title:'文件版本记录',width:900,height:520,content:'<textarea readonly style="width:100%;height:430px">'+$('<div>').text(text).html()+'</textarea>',cancel:true});});});
		}).fail(function(xhr){Tips.tips((xhr.responseJSON&&xhr.responseJSON.data)||'读取 Agent 失败','warning');});
	}

	function updateHistory(){
		$.get('{{updateHistoryApi}}').done(function(r){if(!r.code){Tips.tips(r.data||'读取失败','warning');return;}var rows=(r.data||[]).map(function(x){return '<tr><td>'+x.id+'</td><td>'+x.fromVersion+' → '+x.toVersion+'</td><td>'+x.status+'</td><td>'+new Date(Number(x.createTime)*1000).toLocaleString()+'</td><td>'+(x.status==='success'?'<button class="btn btn-sm aidrive-rollback" data-id="'+x.id+'">回滚</button>':'')+'</td></tr>';}).join('');var d=$.dialog({title:'更新历史与回滚',width:720,content:'<table class="table"><tr><th>ID</th><th>版本</th><th>结果</th><th>时间</th><th></th></tr>'+rows+'</table>',cancel:true});d.$main.on('click','.aidrive-rollback',function(){var id=$(this).data('id');$.dialog.confirm('将先备份当前插件，再恢复所选版本。网盘文件数据不会被修改。确认回滚？',function(){$.ajax({url:'{{updateRollbackApi}}',type:'POST',contentType:'application/json',data:JSON.stringify({id:id}),dataType:'json'}).done(function(x){if(!x.code){Tips.tips(x.data||'回滚失败','warning');return;}location.reload();});});});});
	}
	function maintenance(){
		$.get('{{maintenanceApi}}').done(function(r){if(!r.code){Tips.tips(r.data||'读取失败','warning');return;}var h=r.data.health||{},items=(r.data.backups||[]).map(function(x){return '<li>'+x.file+' · '+x.size+' bytes · '+new Date(Number(x.modifyTime)*1000).toLocaleString()+'</li>';}).join('');var d=$.dialog({title:'数据保护与存储健康',width:650,content:'<div class="aidrive-space-note">数据目录：'+(h.dataWritable?'可写':'不可写')+'　插件目录：'+(h.pluginWritable?'可写':'不可写')+'　可用空间：'+Math.round(Number(h.diskFree||0)/1073741824)+' GB<br>缺失版本文件：'+Number(h.missingVersionBlobs||0)+'</div><p><button class="btn btn-primary aidrive-backup-now">立即备份元数据</button></p><ul>'+items+'</ul>',cancel:true});d.$main.on('click','.aidrive-backup-now',function(){$.post('{{maintenanceApi}}').done(function(x){if(!x.code){Tips.tips(x.data||'备份失败','warning');return;}Tips.tips('备份完成：'+x.data.file);d.close();});});});
	}

	function createAgent(){
		var html='<div class="aidrive-create-form"><div class="aidrive-space-note">账号将自动加入“智能体”部门，并同时拥有个人空间和部门共享空间。</div>'+
			'<label>智能体名称</label><input name="name" placeholder="例如：OpenClaw 财务助手">'+
			'<label>登录账号（英文，可留空自动生成）</label><input name="username" placeholder="例如：openclaw_finance">'+
			'<label>登录密码（可留空自动生成）</label><input name="password" type="password" placeholder="留空将生成高强度密码"></div>';
		var busy=false;var dialog=$.dialog({title:'新建智能体账号',width:520,content:html,okVal:'创建并生成接入话术',ok:function(){
			if(busy)return false;var $box=dialog.$main;var payload={name:$.trim($box.find('[name=name]').val()),username:$.trim($box.find('[name=username]').val()),password:$box.find('[name=password]').val(),sizeMax:0};
			if(!payload.name){Tips.tips('请填写智能体名称','warning');return false;}busy=true;
			$.ajax({url:'{{agentsApi}}',type:'POST',contentType:'application/json',data:JSON.stringify(payload),dataType:'json'}).done(function(result){
				if(!result.code){Tips.tips(result.data||'创建失败','warning');return;}dialog.close();showBinding(result.data);Events.trigger('admin.member.refresh');
			}).fail(function(xhr){Tips.tips((xhr.responseJSON&&xhr.responseJSON.data)||'创建失败','warning');}).always(function(){busy=false;});return false;
		},cancel:true});
	}

	function checkUpdate(){
		var $button=$('.aidrive-update-btn');if($button.hasClass('disabled'))return;
		$button.addClass('disabled').text('正在检查…');
		$.get('{{updateCheckApi}}').done(function(result){
			if(!result.code){Tips.tips(result.data||'检查更新失败','warning');return;}
			var data=result.data;if(!data.hasUpdate){Tips.tips('当前已是最新版 '+data.currentVersion);return;}
			var notes=$('<div>').text(data.notes||'').html();
			var html='<div class="aidrive-update-dialog"><div class="aidrive-space-note"><b>发现新版本 '+data.latestVersion+'</b><br>当前版本：'+data.currentVersion+'</div><p>更新前会自动备份当前 AI Drive 插件；下载包必须通过 SHA-256 校验。</p><div style="max-height:180px;overflow:auto;white-space:pre-wrap;background:#f7f8fa;padding:10px">'+notes+'</div></div>';
			$.dialog({title:'AI Drive 在线更新',width:580,content:html,okVal:'立即更新',ok:function(){installUpdate();return false;},cancel:true});
		}).fail(function(xhr){Tips.tips((xhr.responseJSON&&xhr.responseJSON.data)||'检查更新失败','warning');}).always(function(){$button.removeClass('disabled').html('<i class="font-icon ri-refresh-line mr-5"></i>检查更新');});
	}

	function installUpdate(){
		Tips.loading('正在下载并安装更新…');
		$.ajax({url:'{{updateInstallApi}}',type:'POST',dataType:'json'}).done(function(result){
			Tips.close();if(!result.code){Tips.tips(result.data||'更新失败','warning');return;}
			$.dialog({title:'更新完成',content:'<div style="padding:15px">AI Drive 已更新到 <b>'+result.data.version+'</b>。页面将重新载入。</div>',ok:function(){location.reload();}});
		}).fail(function(xhr){Tips.close();Tips.tips((xhr.responseJSON&&xhr.responseJSON.data)||'更新失败','warning');});
	}

	function mountButton(){
		if(!window.Router||String(Router.hash).indexOf('admin/user')!==0)return;
		var $native=$('[data-action="user-add"]').first();if(!$native.length||$('.aidrive-agent-btn').length)return;
		var $group=$('<div class="btn-group btn-group-sm ml-10"><button type="button" class="btn aidrive-agent-btn"><i class="font-icon ri-robot-2-line mr-5"></i>新建智能体</button><button type="button" class="btn btn-default aidrive-manage-btn"><i class="font-icon ri-dashboard-line mr-5"></i>Agent 控制台</button><button type="button" class="btn btn-default aidrive-guide-btn"><i class="font-icon ri-book-open-line mr-5"></i>使用指南</button><button type="button" class="btn btn-default aidrive-maintenance-btn"><i class="font-icon ri-shield-check-line mr-5"></i>数据保护</button><button type="button" class="btn btn-default aidrive-update-btn"><i class="font-icon ri-refresh-line mr-5"></i>检查更新</button><button type="button" class="btn btn-default aidrive-history-btn"><i class="font-icon ri-history-line mr-5"></i>更新历史</button></div>');
		$native.closest('.btn-group').after($group);$group.find('.aidrive-agent-btn').on('click',createAgent);$group.find('.aidrive-manage-btn').on('click',manageAgents);$group.find('.aidrive-guide-btn').on('click',function(){window.open('https://github.com/13220103449/ai-drive-kodbox/blob/main/AGENT_GUIDE.md','_blank');});$group.find('.aidrive-maintenance-btn').on('click',maintenance);$group.find('.aidrive-update-btn').on('click',checkUpdate);$group.find('.aidrive-history-btn').on('click',updateHistory);
	}
	setInterval(mountButton,500);mountButton();
});
