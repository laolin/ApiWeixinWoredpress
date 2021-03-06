<?php

class class_bindwx{
  
  //先写死域名列表在这里
  static function _verify_DOMAIN($dom) {
    if(in_array($dom, [
      'qinggaoshou.com','www.qinggaoshou.com','api.qinggaoshou.com','app.qinggaoshou.com',
      'linjp.com','api.linjp.com','app.linjp.com',
      'linjp.cn','api.linjp.cn','app.linjp.cn',
      'laolin.com','www.laolin.com','api.laolin.com','app.laolin.com',
      'localhost','127.0.0.1'
    ])) return true;
    
    if( preg_match('/^\w+\.linjp\.cn$/i', $dom))return true;
    if( preg_match('/^\w+\.laolin\.com$/i', $dom))return true;
    if( preg_match('/^\w+\.qinggaoshou\.com$/i', $dom))return true;
    if( preg_match('/^192\.168\.\d{1,3}\.\d{1,3}$/i', $dom))return true;
    if( preg_match('/^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/i', $dom))return true;
    if( preg_match('/^172\.[123]{1,1}\d\.\d{1,3}\.\d{1,3}$/i', $dom))return true;
    return false;
  }
  public static function main( ) {

    $res=API::data(['time'=>time()]);
    return $res;
  }
  
  /**
   *  
非微信开放邢台指定域名页面使用微信扫码登录方法，

1，非微信开放平台指定域名 页面（自己的其他网站A），
可显示二维码扫描。

2，微信开放平台调用指定域名内（自己的网站B）的回调页面：
网站B通过state，识别是来自A站，实现跳回A站的页面。
并带上code。
见 API： callback_xdom

3，A站拿到code是不能直接获取 微信信息的，但是可能在 B 站做一个API，通过code，取得用户信息以JSONP，返回给A站。
见 API： callback_auth
   */
  /** 
   *  callback_xdom 这个API正确执行完成不是返回JSON数据
   *  这个API是给微信服务器回调的
   *  所以设计此API返回一个302跳转，实现回到客户端页面。
   *  （见上面注解的第2点。）
   */
   // $para1是回调的URL地址的 base64_encode
  public static function callback_bridge( $para1,$para2 ) {
    $code=API::GET('code');
    $state=urldecode(API::GET('state'));
    $ss=explode('~',$state);
    if(! $code) {
      return API::data(['invalid code',$code,$state]);
    }
    if(count($ss)!=2) {
      return API::data(['state item != 2',$code,$state]);
    }
    if( $ss[0]!='cb_xd' ) { //固定字符串，同客户端app统一约定的
      return API::data(['state item 1 error: ',$ss[0],$code,$state]);
    }
    $apps=api_g('WX_APPS');
    if(! isset( $apps[$ss[1]] )) {
      return API::data(['invalid app-name: ',$ss[1],$code,$state]);
    }
    
    
    //告诉客户端再调用的服务端 API 的路径
    $apipath=(isset($_SERVER['HTTPS'])? 'https://':'http://') .$_SERVER["HTTP_HOST"].
      explode('callback_xdom',$_SERVER['REQUEST_URI'])[0];
    
    //由于 base64的第64个字符是 '/'，客户端已替换为 '_'，故替换回来
    $url=base64_decode(str_replace('_','/',$para1));//回调的 APP （客户端网页）地址
    $pageTo=base64_decode($para2);//（客户端网页）登录成功后再次重定向的地址
    $urlObj=parse_url($url);
    if(!isset($urlObj['scheme'])||!isset($urlObj['host'])||!isset($urlObj['path'])) {
      return API::data(['invalid URL:',$url,$code,$state]);
    }
    if( ! self::_verify_DOMAIN($urlObj['host']) ) {
      return API::data(['invalid domain:',$url,$code,$state]);
    }
    
    
    // 回调的 APP （客户端网页） URI 信息
      
    /*
    $url="$urlObj[scheme]://$urlObj[host]";
    if(isset($urlObj['port']))
      $url.=":$urlObj[port]";
    $url.="$urlObj[path]?";
    if(isset($urlObj['query']))
      $url.=$urlObj['query']."&";
    $url.="_ret_code=$code&_ret_app=".$ss[1];
  
    if(isset($urlObj['fragment']))
      $url.="#$urlObj[fragment]";
    */
    $url .= "?_ret_code=$code&_ret_app=".$ss[1]."&pageTo=$pageTo";
    header('Location: ' .$url);
    return false;//die();
    //return API::data([$url,$code,$state,$urlObj]);
 }
  
  public static function callback_auth( $para1, $para2 ) {
    $code=API::GET('code');
    $app=API::GET('app');
    $clientId=API::GET('clientid');
    if(strlen($code)<10)return API::msg(1001,'Error auth code.');
    return BINDWX::oauth($code,$app,$clientId);
  }
  
}
class BINDWX{
  static public function oauth($code,$app,$clientId){
    //1，微信回传来的$code
    //$ret=[];
    
    //$ret[]=$code;

    $apps=api_g('WX_APPS');
    if(!isset($apps[$app])) {
      return API::msg(1001,'Error app name');
    }
    $appid=$apps[$app][0];
    $appsec=$apps[$app][1];
    //2, 根据code，换回 access_token 
    $url = 'https://api.weixin.qq.com/sns/oauth2/access_token?appid=' . $appid . '&secret=' . $appsec . '&code=' . $code . '&grant_type=authorization_code';
    $json_token = json_decode(file_get_contents($url),true);
    
    if(!$json_token['access_token'])
      return API::msg(1001,'Error get access_token');
    //$ret[]=$json_token;

    
    //3, 获取用户信息
    $user_info=[];
    
    $info_url = 'https://api.weixin.qq.com/sns/userinfo?access_token=' . $json_token['access_token'] . '&openid=' . $json_token['openid'];
    $uif = json_decode(file_get_contents($info_url),true);

    //取9个字段，名字要和数据库要对应
    $user_info['openid']=$uif['openid'];
    $user_info['unionid']=$uif['unionid'];
    $user_info['nickname']=$uif['nickname'];
    $user_info['headimgurl']=$uif['headimgurl'];
    $user_info['sex']=$uif['sex'];
    $user_info['language']=$uif['language'];
    $user_info['city']=$uif['city'];
    $user_info['province']=$uif['province'];
    $user_info['country']=$uif['country'];
   
    if($appid==$apps['main'][0]) {
      $sys_mp_tk=WX::GetToken();
      $info_url = 'https://api.weixin.qq.com/cgi-bin/user/info?access_token=' . $sys_mp_tk . '&openid=' . $json_token['openid'];
      $uif = json_decode(file_get_contents($info_url),true);
      //取另3个字段，名字要和数据库要对应
      $user_info['subscribe']=$uif['subscribe'];
      $user_info['subscribe_time']=$uif['subscribe_time'];
      $user_info['groupid']=$uif['groupid'];
    }
    

    
    if(!$user_info['openid'])
      return API::msg(1001,'Error get user_info');
    
    //$ret[]=$user_info;
    $uname='wx-'.substr($user_info['openid'],-8);
    $newUserId = WX::save_user($appid,$user_info,$uname);
    $tokenid=$app.'~'.$clientId;
    $newToken=USER::__ADMIN_addToken($newUserId,$tokenid);
    $newToken['uname']=$uname;
    $newToken['wxinfo']=$user_info;
    //TODO 下面又多了一次 SQL 查询，待优化
    $newToken['rights']=USER::getUserRights($newUserId);
    return API::data($newToken);

  }
}
