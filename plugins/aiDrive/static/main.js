kodReady.push(function(){
	$.addStyle('.aidrive-agent-btn{color:#fff!important;background:#4f6ef7!important;border-color:#4f6ef7!important}.aidrive-create-form label{display:block;margin:12px 0 5px;color:#555}.aidrive-create-form input{width:100%;height:36px;padding:6px 10px;border:1px solid #ddd;border-radius:4px}.aidrive-bind-result textarea{width:100%;height:320px;resize:none;padding:12px;line-height:1.65;border:1px solid #ddd;border-radius:5px;background:#f8fafc}.aidrive-space-note{padding:10px 12px;background:#eef4ff;border-radius:5px;color:#456;margin-bottom:12px}');

	function showBinding(data){
		var text=data.copyPrompt||'';
		var html='<div class="aidrive-bind-result"><div class="aidrive-space-note"><b>绑定空间：</b>默认是“个人空间”；协作文件放在“我在的部门 / 智能体”。</div>'+
			'<p>以下连接信息只完整显示这一次。请复制整段话术发给对应的 Agent：</p><textarea readonly></textarea></div>';
		var dialog=$.dialog({title:'智能体账号已创建',width:680,height:510,content:html,okVal:'复制全部话术',ok:function(){$.copyText(text);Tips.tips('接入话术已复制');return false;},cancelVal:'完成',cancel:true});
		dialog.$main.find('textarea').val(text).on('click',function(){this.select();});
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
		var $group=$('<div class="btn-group btn-group-sm ml-10"><button type="button" class="btn aidrive-agent-btn"><i class="font-icon ri-robot-2-line mr-5"></i>新建智能体</button><button type="button" class="btn btn-default aidrive-guide-btn"><i class="font-icon ri-book-open-line mr-5"></i>Agent 使用指南</button><button type="button" class="btn btn-default aidrive-update-btn"><i class="font-icon ri-refresh-line mr-5"></i>检查更新</button></div>');
		$native.closest('.btn-group').after($group);$group.find('.aidrive-agent-btn').on('click',createAgent);$group.find('.aidrive-guide-btn').on('click',function(){window.open('https://github.com/13220103449/ai-drive-kodbox/blob/main/AGENT_GUIDE.md','_blank');});$group.find('.aidrive-update-btn').on('click',checkUpdate);
	}
	setInterval(mountButton,500);mountButton();
});
