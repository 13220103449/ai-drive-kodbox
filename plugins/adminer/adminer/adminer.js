(function(){
	$(window).ready(function(){
		var $main = $('body');
		var showTypeKey = '_menuHidden_';
		keepscrollTop();
		
		$('<div class="toggle-menu"></div>').appendTo($main);
		if($(window).width() < 769){
			$main.addClass('app-page-small');
			$main.setClass('menu-hide',storeValue(showTypeKey) != 'hide');
		}
		
		$('.toggle-menu').bind('click',function(){
			$main.toggleClass('menu-hide');
			var showType = $main.hasClass('menu-hide') ? 'show' : 'hide';
			storeValue(showTypeKey, showType);
			keepscrollTop();
		});
	});
	
	var storeValue = function(key,valueSet){
		var storeKey = 'kodbox_adminer_options';
		var option   = jsonDecode(localStorage.getItem(storeKey)) || {};
		if(!_.isObject(option)){option = {};}
		console.log(34,option);
		if(valueSet !== undefined){
			option[key] = valueSet;
			if(valueSet === null){delete option[key];}
			localStorage.setItem(storeKey,jsonEncode(option));
		}else{
			return option[key];
		}
	};
	var keepscrollTop = function(isSave){
		var $scroll = $("#tables");
		var table = _.get($.parseUrl(),'params.db','');
		if(!$scroll.is(':visible')){return;}
		if(isSave){
			var scrollTop = $scroll.scrollTop();
			storeValue(table,scrollTop <= 0 ? null : scrollTop);
		}else{
			var scrollTop = storeValue(table);
			$scroll.scrollTop(parseInt(scrollTop) || 0);
		}
	};
	
	$(window).bind('beforeunload',function(e){
		keepscrollTop(true);
	});
})();