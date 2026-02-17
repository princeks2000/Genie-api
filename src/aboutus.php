<?php
class aboutus extends model
{
public function companydetails($args){
	$data = $this->from('legaldoc')->select(null)->select('text')->where('status','1')->fetch();
	return $this->out(['status'=>true,'data'=>$data],200);
}
public function termsandcondtions($args){
	$data = $this->from('termsandcondtions')->select(null)->select('text')->where('status','1')->orderBy('`order` ASC')->fetchAll();

	return $this->out(['status'=>true,'data'=>array_column($data, 'text')],200);
}
public function privacy($args){
	$data = $this->from('privacy')->select(null)->select('text')->where('status','1')->orderBy('`order` ASC')->fetchAll();
	return $this->out(['status'=>true,'data'=>array_column($data, 'text')],200);
}
public function legaldoc($args){
	$data = $this->from('legaldoc')->select(null)->select('text')->where('status','1')->orderBy('`order` ASC')->fetchAll();
	return $this->out(['status'=>true,'data'=>array_column($data, 'text')],200);
}
public function rulesandregulations($args){
	$text = $this->from('rules_and_regulations')->where('id','1')->fetch('text');
	return $this->out(['status'=>true,'data'=>json_decode($text)],200);
}

}
