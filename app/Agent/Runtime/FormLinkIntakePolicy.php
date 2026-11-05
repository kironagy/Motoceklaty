<?php
namespace App\Agent\Runtime;
class FormLinkIntakePolicy
{
 public function asksForPrivateData(string $text):bool
 {
  $text=\App\Support\ArabicTextNormalizer::normalize($text);
  return (bool)preg_match('/(?:ابعتلي|ابعثلي|ابعت ليا|اكتبلي|اكتب لي|قولي|قوللي|هات|ابعت|ابعث)\s*.{0,65}(?:اسمك|بياناتك|البيانات|بطاق|رقمك|رقم تليفون|رقم موبايل|عنوانك|الرقم القومي|مستنداتك|ورقك|صور المستند)/u',$text)
   && !preg_match('/(?:امل[اأ]|ارفع|اكتب|ابعت).*(?:في|علي|داخل)\s*(?:ال)?(?:رابط|فورم|لينك|استماره)/u',$text);
 }
}
