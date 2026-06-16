# AGENTS.md

## General Rules
- Luôn ưu tiên sửa tối thiểu, đúng phạm vi yêu cầu.
- Không được rewrite toàn bộ file nếu chỉ cần sửa một đoạn nhỏ.
- Trước khi thay đổi logic liên quan form, phải đọc cả frontend và backend liên quan.
- Nếu gây ra lỗi do chính mình tạo trong cùng phiên, phải sửa ngay, không hỏi lại người dùng có muốn sửa hay không.

## Encoding Rules
- Tuyệt đối không được làm hỏng tiếng Việt trong bất kỳ file nào.
- Mọi file có tiếng Việt phải được giữ ở UTF-8 khong BOM, trừ khi file đó đang dùng chuẩn khác và có lý do rõ ràng để giữ nguyên.
- Không dùng cách ghi đè cả file cho file có tiếng Việt nếu chưa thật sự cần thiết.
- Với file có tiếng Việt, ưu tiên patch cục bộ thay vì replace toàn file.
- Không được dùng các cách ghi file dễ làm đổi encoding cho file có tiếng Việt, như ghi đè toàn file bằng shell hoặc script, trừ khi không còn lựa chọn an toàn hơn.
- Nếu buộc phải dựng lại toàn bộ file có tiếng Việt, phải lấy lại từ bản gốc đáng tin cậy rồi chèn thay đổi tối thiểu, không được tự tái tạo nội dung tiếng Việt bằng tay khi không cần.
- Sau khi sửa file có tiếng Việt, phải kiểm tra lại để chắc không xuất hiện chuỗi lỗi mã hóa như: `Ã`, `á»`, `Ä`, `Â`.
- Sau mỗi lần sửa file có tiếng Việt, phải rà soát ngay chính file đó trước khi kết thúc lượt làm việc.
- Nếu phát hiện lỗi mã hóa do assistant gây ra, phải ưu tiên sửa lỗi encoding trước mọi thay đổi khác.

## Verification Rules
- Sau khi sửa file PHP, phải chạy `php -l` cho các file đã thay đổi nếu có thể.
- Sau khi sửa file có tiếng Việt, phải rà soát nhanh các chuỗi lỗi mojibake bằng tìm kiếm pattern như: `Ã|á»|Ä|Â`.
- Khi sửa file có tiếng Việt, phải ưu tiên kiểm tra diff của đúng file vừa sửa để xác nhận không có text tiếng Việt bị biến dạng.
- Khi sửa form submit, phải kiểm tra cả các cơ chế liên quan như CSRF, timestamp anti-bot, session, cache và rate limit.

## Safety Rules
- Không được dùng lệnh phá hủy hoặc khôi phục diện rộng nếu người dùng chưa yêu cầu rõ.
- Không được tự ý revert thay đổi của người dùng.
- Nếu cần sửa một file đã có dấu hiệu encoding nhạy cảm, phải giữ nguyên nội dung tiếng Việt hiện có và chỉ chỉnh phần thật sự cần thiết.

## Sub-Agent Registry
- Use these stable role names in future chats: coder1, tester1, tester2, design1.
- UI nicknames shown by the tool may differ; the role names above are the stable names to use.

### coder1
- Model: gpt-5.4
- Scope: write code for the project, prefer minimal patches, preserve Vietnamese text safely, do not revert user changes.

### tester1
- Model: gpt-5.4
- Scope: review security issues and code bugs, check regressions, validate input handling, auth, CSRF, session, and rate limits.

### tester2
- Model: gpt-5.4
- Scope: review Vietnamese font/encoding issues, detect mojibake, and watch risky line-ending or encoding changes.

### design1
- Model: gpt-5.4
- Scope: handle UI/UX and frontend design tasks.
- Required skill: .agent/skills/ui-ux-pro-max/SKILL.md
- Rule: for UI work, follow the skill workflow before implementing when appropriate.
