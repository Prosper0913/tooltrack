<?php
// Shared student rules for manual registration, editing and both import stages.
function studentText($value): string {
    $text=str_replace("\xC2\xA0",' ',(string)$value);
    return trim((string)preg_replace('/\s+/u',' ',preg_replace('/[\x00-\x1f\x7f]/',' ',$text)));
}
function studentNumericText($value): string {
    $s=studentText($value);
    if(preg_match('/^\d+(?:\.\d+)?[eE]\+?\d+$/',$s)&&is_finite((float)$s)&&(float)$s<1e15)$s=sprintf('%.0f',(float)$s);
    if(preg_match('/^(\d+)\.0+$/',$s,$m))$s=$m[1];
    return $s;
}
function studentYearNumber($value): int {
    $s=strtolower(studentNumericText($value));
    $s=str_replace(['first','second'],['1','2'],$s);
    if(preg_match('/^(?:(?:year|yr|grade|g)\s*)?(1|2|11|12)(?:st|nd|th)?(?:\s*(?:year|yr|grade))?$/',$s,$m))return (int)$m[1];
    return 0;
}
function studentYearLabel(int $year): string {
    return [1=>'1st Year',2=>'2nd Year',11=>'Grade 11',12=>'Grade 12'][$year]??'';
}
function studentProgram($value): string {return strtoupper(studentText($value));}
function studentPrograms(): array {return ['FPST','TVL','TESDA'];}
function normalizeStudent(array $input): array {
    $id=(string)preg_replace('/[\s-]/','',studentNumericText($input['id_number']??''));
    $name=studentText($input['full_name']??'');
    $course=studentProgram($input['course']??'');
    $section=studentText($input['section_name']??'');
    $year=studentYearNumber($input['year_level']??'');
    $phone=(string)preg_replace('/[\s().-]/','',studentNumericText($input['phone']??''));
    if(strpos($phone,'+63')===0)$phone='0'.substr($phone,3);
    elseif(preg_match('/^63\d{10}$/',$phone))$phone='0'.substr($phone,2);
    elseif(preg_match('/^9\d{9}$/',$phone))$phone='0'.$phone;
    $errors=[];
    if($course==='TESDA'){
        $year=0;
        if(!preg_match('/^\d{1,50}$/',$id))$errors[]='TESDA Student ID must be 1 to 50 digits (numbers only).';
    }elseif(!preg_match('/^\d{8,10}$/',$id))$errors[]='Student ID must be 8 to 10 digits (numbers only).';
    if(mb_strlen($name)<2||mb_strlen($name)>150)$errors[]='Full Name must be 2 to 150 characters.';
    if(!in_array($course,studentPrograms(),true))$errors[]='Invalid Program "'.$course.'". Program must be FPST, TVL or TESDA.';
    if(($section===''&&$course!=='TESDA')||mb_strlen($section)>80)$errors[]='Section is required for FPST/TVL and must be at most 80 characters (for example Rizal, Bonifacio, A or B).';
    if($course==='FPST'&&!in_array($year,[1,2],true))$errors[]='FPST Year Level must be Year 1 or Year 2.';
    if($course==='TVL'&&!in_array($year,[11,12],true))$errors[]='TVL Year Level must be Grade 11 or Grade 12.';
    if($phone!==''&&!preg_match('/^09\d{9}$/',$phone))$errors[]='Phone Number must be blank or a valid mobile number, for example 09171234567 or +639171234567.';
    return ['id_number'=>$id,'full_name'=>$name,'course'=>$course,'section_name'=>$section,'year'=>$year,
            'year_level'=>studentYearLabel($year),'phone'=>$phone,'errors'=>$errors];
}
