<?php
// includes/blocks/faq.php

$hb_layout = $block['layout_style'] ?? 'simple';
$hb_wave_top = $block['wave_top_color'] ?? '#f8f9fa';
$hb_wave_bottom = $block['wave_bottom_color'] ?? '#ffffff';
if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', (string) $hb_wave_top)) {
    $hb_wave_top = '#f8f9fa';
}
if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', (string) $hb_wave_bottom)) {
    $hb_wave_bottom = '#ffffff';
}

$faq_section_class = 'faq-style-simple';
switch ($hb_layout) {
    case 'wave':
        $faq_section_class = 'faq-style-wave';
        break;
    case 'gradient':
        $faq_section_class = 'faq-style-gradient';
        break;
    case 'glass':
        $faq_section_class = 'faq-style-glass';
        break;
    case 'aurora':
        $faq_section_class = 'faq-style-aurora';
        break;
    case 'sunset':
        $faq_section_class = 'faq-style-sunset';
        break;
    case 'minimal':
        $faq_section_class = 'faq-style-minimal';
        break;
    case 'neon':
        $faq_section_class = 'faq-style-neon';
        break;
    case 'editorial':
        $faq_section_class = 'faq-style-editorial';
        break;
}

// Chế độ boxed (trang chủ có sidebar): render gọn trong thẻ card, bỏ nền full + wave.
$__faq_boxed = ($GLOBALS['block_context'] ?? 'full') === 'boxed';
if ($__faq_boxed) {
    $faq_section_class = 'faq-box';
    $hb_layout = 'simple';
}
?>

<?php
// FAQPage schema (rất tốt cho GEO/AI answer engines)
if (!empty($faqs) && is_array($faqs)) {
    $__faq_entities = [];
    foreach ($faqs as $__f) {
        $__q = trim((string) ($__f['question'] ?? ''));
        $__a = trim(strip_tags((string) ($__f['answer'] ?? '')));
        if ($__q === '' || $__a === '') continue;
        $__faq_entities[] = [
            '@type' => 'Question',
            'name' => $__q,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $__a],
        ];
    }
    if (!empty($__faq_entities) && function_exists('jsonld_script')) {
        echo jsonld_script([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $__faq_entities,
        ]);
    }
}
?>

<section class="position-relative overflow-hidden <?php echo e($faq_section_class); ?>" style="<?php echo function_exists('block_spacing_style') ? block_spacing_style($hb_layout === 'wave' ? 80 : 44, $hb_layout === 'wave' ? 80 : 44) : 'padding:44px 0;'; ?>">
    <?php if ($hb_layout === 'wave'): ?>
        <div class="faq-wave faq-wave-top"><svg viewBox="0 0 1440 120" preserveAspectRatio="none"><path fill="<?php echo e($hb_wave_top); ?>" d="M0,60 C360,120 720,0 1440,60 L1440,0 L0,0 Z"></path></svg></div>
        <div class="faq-wave faq-wave-bottom"><svg viewBox="0 0 1440 120" preserveAspectRatio="none"><path fill="<?php echo e($hb_wave_bottom); ?>" d="M0,60 C360,0 720,120 1440,60 L1440,120 L0,120 Z"></path></svg></div>
    <?php endif; ?>

    <div class="container position-relative" style="z-index:2">
        <div class="text-center mb-5">
            <h2 class="fw-bold">Câu Hỏi Thường Gặp</h2>
            <div class="d-inline-block rounded-pill" style="height:4px;width:60px;background:var(--primary-gradient);"></div>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="accordion" id="faqAccordion">
                    <?php foreach ($faqs as $index => $faq): ?>
                        <div class="accordion-item border-0 mb-3 shadow-sm rounded overflow-hidden">
                            <h2 class="accordion-header" id="heading<?php echo $index; ?>">
                                <button class="accordion-button <?php echo $index !== 0 ? 'collapsed' : ''; ?> fw-bold bg-white"
                                        type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $index; ?>"
                                        aria-expanded="<?php echo $index === 0 ? 'true' : 'false'; ?>"
                                        aria-controls="collapse<?php echo $index; ?>">
                                    <?php echo e($faq['question']); ?>
                                </button>
                            </h2>
                            <div id="collapse<?php echo $index; ?>"
                                 class="accordion-collapse collapse <?php echo $index === 0 ? 'show' : ''; ?>"
                                 aria-labelledby="heading<?php echo $index; ?>" data-bs-parent="#faqAccordion">
                                <div class="accordion-body bg-white text-muted">
                                    <?php echo nl2br(e($faq['answer'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
.faq-style-simple { background: #f8f9fa; }
.faq-style-gradient { background: linear-gradient(135deg, #f3f4ff 0%, #eef2ff 100%); }
.faq-style-glass {
    background: linear-gradient(135deg, #1e1b4b 0%, #4c1d95 100%);
}
.faq-style-aurora { background: linear-gradient(135deg, #0f172a 0%, #312e81 45%, #0284c7 100%); }
.faq-style-sunset { background: linear-gradient(135deg, #f97316 0%, #ec4899 45%, #b91c1c 100%); }
.faq-style-neon { background: linear-gradient(135deg, #020617 0%, #0f172a 50%, #1d4ed8 100%); }
.faq-style-minimal {
    background: #f8fafc;
    background-image: linear-gradient(rgba(15, 23, 42, 0.06) 1px, transparent 1px), linear-gradient(to right, rgba(15, 23, 42, 0.05) 1px, transparent 1px);
    background-size: 48px 48px;
}
.faq-style-editorial {
    background: #f8fafc;
    background-image: linear-gradient(rgba(15, 23, 42, 0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(15, 23, 42, 0.04) 1px, transparent 1px);
    background-size: 52px 52px;
}
.faq-style-glass h2 { color: #fff; }
.faq-style-glass .accordion-item,
.faq-style-glass .accordion-button,
.faq-style-glass .accordion-body {
    background: rgba(255,255,255,0.9) !important;
}
.faq-style-aurora h2,
.faq-style-sunset h2 {
    color: #fff;
}
.faq-style-neon h2 { color: #fff; }
.faq-style-neon .accordion-item,
.faq-style-neon .accordion-button,
.faq-style-neon .accordion-body {
    background: rgba(255,255,255,0.95);
}
.faq-style-wave { background: #eef2ff; }
.faq-wave { position: absolute; left: 0; width: 100%; height: 80px; z-index: 1; }
.faq-wave-top { top: 0; }
.faq-wave-bottom { bottom: 0; }
.faq-wave svg { width: 100%; height: 100%; }
</style>
