<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DonationCaseController extends Controller
{
    public function index(string $locale)
    {
        app()->setLocale($locale);

        $category = request('category');

        $cases = $this->allCases();

        $filtered = $category
            ? $cases->where('category', $category)->values()
            : $cases;

        return view('cases.index', [
            'cases'    => $filtered,
            'category' => $category,
        ]);
    }

    public function show(string $locale, int $id)
    {
        app()->setLocale($locale);

        $cases = $this->allCases();
        $case  = $cases->firstWhere('id', $id);

        abort_if(is_null($case), 404);

        return view('cases.show', compact('case'));
    }

    private function allCases(): \Illuminate\Support\Collection
    {
        return collect([
            // Health
            [
                'id'             => 1,
                'title_ar'       => 'علاج طفل مريض بالقلب',
                'title_en'       => 'Heart Surgery for a Sick Child',
                'description_ar' => 'يُعاني أحمد، طفلٌ في الخامسة من عمره، من عيب خلقي في القلب يُهدد حياته يومياً. رصدت فرقتنا الميدانية حالته في إحدى القرى النائية، حيث تعجز أسرته عن تحمّل تكاليف العلاج. تبرعاتكم ستُموّل العملية الجراحية الكاملة، بما في ذلك التخدير والإقامة في المستشفى وإعادة التأهيل، لتمنحوا هذا الطفل فرصةً حقيقية في الحياة.',
                'description_en' => 'Ahmed, a five-year-old boy, suffers from a congenital heart defect that threatens his life every day. Our field team identified his case in a remote village where his family cannot afford treatment. Your donations will fund the complete surgical procedure, including anaesthesia, hospital stay, and rehabilitation, giving this child a real chance at life.',
                'category'       => 'health',
                'goal'           => 50000,
                'raised'         => 32000,
                'image_url'      => 'https://images.unsplash.com/photo-1584820927498-cfe5211fd8bf?w=600',
            ],
            [
                'id'             => 2,
                'title_ar'       => 'توفير أدوية لمرضى السرطان',
                'title_en'       => 'Cancer Patients Medication Fund',
                'description_ar' => 'يواجه عشرات المرضى في مراحل متقدمة من السرطان خطر توقف علاجهم بسبب نقص التمويل. هذا البرنامج يضمن استمرارية توفير الأدوية الكيميائية والمسكّنات لمدة ستة أشهر كاملة لأكثر من ثلاثين مريضاً. كل ريال تتبرع به يُطيل العمر ويُخفف المعاناة.',
                'description_en' => 'Dozens of patients in advanced stages of cancer face the risk of their treatment stopping due to lack of funding. This programme ensures a continuous supply of chemotherapy drugs and pain relief for six full months for over thirty patients. Every riyal you donate extends life and reduces suffering.',
                'category'       => 'health',
                'goal'           => 80000,
                'raised'         => 45000,
                'image_url'      => 'https://images.unsplash.com/photo-1576091160550-2173dba999ef?w=600',
            ],
            // Orphans
            [
                'id'             => 3,
                'title_ar'       => 'كفالة أيتام في اليمن',
                'title_en'       => 'Sponsoring Orphans in Yemen',
                'description_ar' => 'في ظل النزاع المستمر باليمن، فقد مئات الأطفال والديهم وباتوا دون مأوى أو رعاية. نسعى من خلال هذه الحملة إلى كفالة خمسين طفلاً يتيماً بتأمين احتياجاتهم الغذائية والتعليمية والصحية لمدة عام كامل. كفالتك لهؤلاء الأطفال هي استثمار في مستقبل أمة.',
                'description_en' => 'Amid the ongoing conflict in Yemen, hundreds of children have lost their parents and are left without shelter or care. Through this campaign we aim to sponsor fifty orphaned children by securing their nutritional, educational, and healthcare needs for a full year. Sponsoring these children is an investment in the future of a generation.',
                'category'       => 'orphans',
                'goal'           => 30000,
                'raised'         => 21000,
                'image_url'      => 'https://images.unsplash.com/photo-1488521787991-ed7bbaae773c?w=600',
            ],
            [
                'id'             => 4,
                'title_ar'       => 'بيت الأيتام – السودان',
                'title_en'       => 'Orphan Home – Sudan',
                'description_ar' => 'يهدف هذا المشروع إلى إنشاء دار رعاية متكاملة في الخرطوم تستوعب أكثر من مئة طفل يتيم، تُوفر لهم المسكن والتعليم والتأهيل النفسي والاجتماعي. ساهم في بناء هذا البيت ليكون صدقةً جاريةً تستمر بعد وفاتك بإذن الله.',
                'description_en' => 'This project aims to establish a fully integrated care home in Khartoum that accommodates more than one hundred orphaned children, providing shelter, education, and psychological and social rehabilitation. Contribute to building this home so it becomes an ongoing charity that continues after your passing, by God\'s will.',
                'category'       => 'orphans',
                'goal'           => 60000,
                'raised'         => 18000,
                'image_url'      => 'https://images.unsplash.com/photo-1594708767771-a7502209ff51?w=600',
            ],
            // Education
            [
                'id'             => 5,
                'title_ar'       => 'بناء مدرسة في المناطق النائية',
                'title_en'       => 'Build a School in Remote Areas',
                'description_ar' => 'تقع هذه القرية على بُعد ثلاثين كيلومتراً من أقرب مدرسة، مما يحرم أطفالها من حق التعليم الأساسي. سيُمكّن هذا المشروع من بناء مدرسة ابتدائية من ثمانية فصول دراسية مزودة بالمياه والكهرباء، لتستقبل أكثر من مئتي طفل في عامها الأول. أسهم في بناء مستقبل هؤلاء الأطفال.',
                'description_en' => 'This village lies thirty kilometres from the nearest school, depriving its children of their basic right to education. This project will enable the construction of an eight-classroom primary school equipped with water and electricity, ready to receive over two hundred children in its first year. Contribute to building the future of these children.',
                'category'       => 'education',
                'goal'           => 120000,
                'raised'         => 75000,
                'image_url'      => 'https://images.unsplash.com/photo-1503676260728-1c00da094a0b?w=600',
            ],
            [
                'id'             => 6,
                'title_ar'       => 'منح دراسية للطلاب الفقراء',
                'title_en'       => 'Scholarships for Underprivileged Students',
                'description_ar' => 'اضطر كثير من الطلاب المتفوقين إلى التخلي عن أحلامهم الجامعية بسبب ضيق ذات اليد. من خلال هذا البرنامج، نمنح منحاً دراسية كاملة لعشرين طالباً وطالبة لتغطية الرسوم والإقامة والكتب لمدة أربع سنوات. أنت قادر على تحويل مسار حياة إنسان بتبرع واحد.',
                'description_en' => 'Many high-achieving students have been forced to abandon their university dreams due to financial hardship. Through this programme, we award full scholarships to twenty male and female students covering tuition, accommodation, and books for four years. You can change the course of a person\'s life with a single donation.',
                'category'       => 'education',
                'goal'           => 40000,
                'raised'         => 29000,
                'image_url'      => 'https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=600',
            ],
            // Housing
            [
                'id'             => 7,
                'title_ar'       => 'بناء منزل لعائلة مشردة',
                'title_en'       => 'Build a Home for a Displaced Family',
                'description_ar' => 'فقدت عائلة الحاج منزلها جراء الفيضانات التي اجتاحت المنطقة الشهر الماضي، وهي تقيم حالياً في خيمة مؤقتة مع أربعة أطفال صغار. تبرعاتكم ستُموّل بناء منزل بسيط من غرفتين وصالة وحمام ومطبخ، ليكون ملجأً آمناً ودافئاً لهذه العائلة.',
                'description_en' => 'The Al-Hajj family lost their home in flooding that swept through the area last month and are currently living in a temporary tent with four young children. Your donations will fund the construction of a simple two-bedroom home with a living room, bathroom, and kitchen, to serve as a safe and warm refuge for this family.',
                'category'       => 'housing',
                'goal'           => 25000,
                'raised'         => 12000,
                'image_url'      => 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=600',
            ],
            [
                'id'             => 8,
                'title_ar'       => 'إعادة إعمار منازل متضررة من الزلزال',
                'title_en'       => 'Rebuild Earthquake-Damaged Homes',
                'description_ar' => 'دمّر الزلزال المدمر الذي ضرب المنطقة قبل أشهر مئات المنازل وشرّد آلاف الأسر. تسعى هذه الحملة إلى إعادة بناء خمسين منزلاً بمواصفات مقاومة للزلازل لتعيد اللحمة والاستقرار لهذه المجتمعات المنكوبة. مساهمتك تبني أملاً فوق الأنقاض.',
                'description_en' => 'The devastating earthquake that struck the region months ago destroyed hundreds of homes and displaced thousands of families. This campaign seeks to rebuild fifty homes to earthquake-resistant specifications, restoring cohesion and stability to these stricken communities. Your contribution builds hope upon the rubble.',
                'category'       => 'housing',
                'goal'           => 200000,
                'raised'         => 110000,
                'image_url'      => 'https://images.unsplash.com/photo-1582560475093-ba66accbc095?w=600',
            ],
            // Water
            [
                'id'             => 9,
                'title_ar'       => 'حفر بئر مياه في أفريقيا',
                'title_en'       => 'Dig a Water Well in Africa',
                'description_ar' => 'تقطع نساء وأطفال هذه القرية مسافة خمسة كيلومترات يومياً للحصول على مياه غير نظيفة، مما يتسبب في تفشي الأمراض وضياع وقت الدراسة. هذه البئر ستُوفر مياهاً نظيفة لأكثر من خمسمئة شخص على مدى عقود. تبرعك اليوم صدقة جارية لا تنقطع.',
                'description_en' => 'Women and children in this village walk five kilometres every day to collect unsafe water, causing the spread of disease and lost school time. This well will provide clean water to more than five hundred people for decades. Your donation today is an ongoing charity that never runs dry.',
                'category'       => 'water',
                'goal'           => 15000,
                'raised'         => 15000,
                'image_url'      => 'https://images.unsplash.com/photo-1541544537156-7627a7a4aa1c?w=600',
            ],
            [
                'id'             => 10,
                'title_ar'       => 'مشروع مياه نظيفة للقرى',
                'title_en'       => 'Clean Water Project for Villages',
                'description_ar' => 'يهدف هذا المشروع الشامل إلى ربط عشر قرى بشبكة مياه نقية عبر مضخات شمسية وخزانات ونقاط توزيع في متناول الجميع. المشروع سيُنهي معاناة أكثر من ثلاثة آلاف شخص مع شُح المياه ويرفع المستوى الصحي لهذه المجتمعات.',
                'description_en' => 'This comprehensive project aims to connect ten villages to a clean water network through solar pumps, storage tanks, and accessible distribution points. The project will end the water-scarcity hardship of more than three thousand people and raise the health standards of these communities.',
                'category'       => 'water',
                'goal'           => 35000,
                'raised'         => 20000,
                'image_url'      => 'https://images.unsplash.com/photo-1473445730015-841f29a9490b?w=600',
            ],
            // Mosques
            [
                'id'             => 11,
                'title_ar'       => 'بناء مسجد في قرية محرومة',
                'title_en'       => 'Build a Mosque in a Deprived Village',
                'description_ar' => 'يؤدي أهل هذه القرية صلواتهم في العراء أو في أكواخ متهالكة منذ سنوات طويلة. يأتي هذا المشروع لبناء مسجد جامع يتسع لثلاثمئة مصلٍّ، مكتملاً بالوضوء والمكتبة وقاعة التعليم. بنيان المساجد من أجلّ القربات وأدومها نفعاً.',
                'description_en' => 'The people of this village have been praying outdoors or in dilapidated huts for many years. This project will build a congregational mosque accommodating three hundred worshippers, complete with ablution facilities, a library, and a teaching hall. Building mosques is among the most noble and enduring acts of charity.',
                'category'       => 'mosques',
                'goal'           => 90000,
                'raised'         => 54000,
                'image_url'      => 'https://images.unsplash.com/photo-1564769662533-4f00a87b4056?w=600',
            ],
            [
                'id'             => 12,
                'title_ar'       => 'ترميم مسجد تاريخي',
                'title_en'       => 'Restore a Historic Mosque',
                'description_ar' => 'يعود تاريخ هذا المسجد العريق إلى أكثر من مئتي عام وهو شاهد حضاري على الإسلام في المنطقة، غير أن الإهمال وعوامل الزمن أوشكت به على الانهيار. يشمل مشروع الترميم الأساسات والجدران والمئذنة والقبة وتجهيز الداخل، لتستعيد هذه التحفة المعمارية مجدها.',
                'description_en' => 'This venerable mosque dates back more than two hundred years and stands as a civilisational testament to Islam in the region, yet neglect and the passage of time have brought it close to collapse. The restoration project covers the foundations, walls, minaret, dome, and interior fittings, so this architectural masterpiece may reclaim its glory.',
                'category'       => 'mosques',
                'goal'           => 70000,
                'raised'         => 41000,
                'image_url'      => 'https://images.unsplash.com/photo-1542816417-0983c9c9ad53?w=600',
            ],
        ]);
    }
}
